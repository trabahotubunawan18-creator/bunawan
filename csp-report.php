<?php
// TEMPORARY: receives Content-Security-Policy violation reports from browsers and writes one line each to the server log.
// No session, no database. Delete this file (and "report-uri" in docker/security-headers.conf) once the policy is enforced.
http_response_code(204);
$raw = file_get_contents('php://input', false, null, 0, 4096);
$data = json_decode((string)$raw, true);
$r = is_array($data) ? ($data['csp-report'] ?? null) : null;
if (is_array($r)) {
    $clean = fn($v) => preg_replace('/[^\x20-\x7E]/', '', substr((string)$v, 0, 200));
    error_log('CSP-REPORT page=' . $clean($r['document-uri'] ?? '?')
        . ' blocked=' . $clean($r['blocked-uri'] ?? '?')
        . ' directive=' . $clean($r['violated-directive'] ?? '?'));
}
