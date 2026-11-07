<?php
require __DIR__ . '/../includes/admin_auth.php';
require_admin();
$pdo = db();

// Filters and pagination
$status = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 25;
$offset = ($page - 1) * $per;

// Build query
$where = ['1=1'];
$params = [];

if ($status !== 'all') {
    $where[] = 'ep.verification_status = ?';
    $params[] = $status;
}
if ($search) {
    $where[] = '(ep.employer_name ILIKE ? OR u.email ILIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSql = implode(' AND ', $where);

// Get employers
$sql = "
    SELECT ep.user_id, ep.employer_name, ep.employer_type, ep.verification_status,
           ep.permit_expiry, ep.verified_at, ep.verification_expires, ep.peso_endorsed,
           ep.approved_post_count, ep.created_at,
           u.email, u.status AS user_status,
           b.name AS barangay
    FROM employer_profiles ep
    JOIN users u ON u.id = ep.user_id
    JOIN barangays b ON b.id = ep.barangay_id
    WHERE $whereSql
    ORDER BY ep.created_at DESC
    LIMIT ? OFFSET ?
";
$stmt = $pdo->prepare($sql);
$stmt->execute([...$params, $per, $offset]);
$employers = $stmt->fetchAll();

// Get total count
$countSql = "SELECT COUNT(*) FROM employer_profiles ep JOIN users u ON u.id = ep.user_id WHERE $whereSql";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

// Get verification expiry reminders (30 days)
$expiring = $pdo->query("
    SELECT ep.user_id, ep.employer_name, ep.verification_expires
    FROM employer_profiles ep
    WHERE ep.verification_status IN ('verified_business', 'verified_local')
      AND ep.verification_expires IS NOT NULL
      AND ep.verification_expires <= (CURRENT_DATE + INTERVAL '30 days')
    ORDER BY ep.verification_expires ASC
    LIMIT 10
")->fetchAll();

$statusLabels = [
    'all' => 'All statuses',
    'pending' => 'Pending',
    'more_info' => 'More info needed',
    'verified_business' => 'Verified Business',
    'verified_local' => 'Verified Local',
    'rejected' => 'Rejected',
    'suspended' => 'Suspended',
];

$pageTitle = 'Employers';
require __DIR__ . '/../includes/admin_top.php';
?>

<?php if ($expiring): ?>
<div class="card panel-card" style="margin-bottom:20px;border-color:var(--gold)">
  <div class="panel-head"><h2>Verification expiring within 30 days</h2></div>
  <?php foreach ($expiring as $e): ?>
    <div class="row-item">
      <div>
        <b><?= e($e['employer_name']) ?></b>
        <div class="small">Expires <?= e(date('M j, Y', strtotime($e['verification_expires']))) ?></div>
      </div>
      <a class="btn btn-outline btn-sm" href="<?= url('admin/verify-review.php?id=' . (int)$e['user_id']) ?>">Review</a>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="page-header">
  <form method="get" class="filter-bar">
    <input type="text" name="search" placeholder="Search by name or email..." value="<?= e($search) ?>">
    <select name="status">
      <?php foreach ($statusLabels as $val => $label): ?>
        <option value="<?= e($val) ?>" <?= $status === $val ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">Filter</button>
    <a class="btn btn-outline" href="<?= url('admin/employers.php') ?>">Clear</a>
  </form>
</div>

<?php if ($employers): ?>
  <div class="card panel-card">
    <div class="table-head">
      <div class="table-info"><?= $total ?> employer(s) found</div>
    </div>

    <div class="table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th>Employer</th>
            <th>Type</th>
            <th>Barangay</th>
            <th>Verification</th>
            <th>Posts</th>
            <th>Registered</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($employers as $e): ?>
            <?php
            $v = verif_info($e['verification_status']);
            $isExpiring = $e['verification_expires'] && strtotime($e['verification_expires']) <= strtotime('+30 days');
            ?>
            <tr>
              <td>
                <div>
                  <strong><?= e($e['employer_name']) ?></strong>
                  <div class="small"><?= e($e['email']) ?></div>
                  <?php if ($e['peso_endorsed']): ?><span class="badge badge-gold">PESO endorsed</span><?php endif; ?>
                </div>
              </td>
              <td><?= ucfirst(str_replace('_', ' ', $e['employer_type'])) ?></td>
              <td><?= e($e['barangay']) ?></td>
              <td>
                <span class="status-badge <?= e($v[1]) ?>"><?= e($v[0]) ?></span>
                <?php if ($isExpiring): ?><span class="badge badge-amber">Expiring soon</span><?php endif; ?>
                <?php if ($e['verified_at']): ?><div class="small">Since <?= e(date('M j, Y', strtotime($e['verified_at']))) ?></div><?php endif; ?>
              </td>
              <td><?= (int)$e['approved_post_count'] ?></td>
              <td><?= e(date('M j, Y', strtotime($e['created_at']))) ?></td>
              <td>
                <div class="action-btns">
                  <a class="btn-icon" href="<?= url('admin/verify-review.php?id=' . (int)$e['user_id']) ?>" title="View details"><?= icon('file', 16) ?></a>
                  <a class="btn-icon" href="<?= url('employer-profile.php?id=' . (int)$e['user_id']) ?>" target="_blank" title="Public profile"><?= icon('user', 16) ?></a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?= pager($total, $page, $per, url('admin/employers.php') . '?' . http_build_query(array_filter(['status' => $status, 'search' => $search]))) ?>

<?php else: ?>
  <div class="card panel-card">
    <div class="mini-empty"><?= icon('building', 28) ?><p>No employers found.</p></div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/dash_bottom.php'; ?>
