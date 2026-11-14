<?php
// The For Employers page now lives on the Guide page, on its "For Employers" tab.
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/helpers.php';
header('Location: ' . url('guide.php') . '#employers', true, 301);
exit;
