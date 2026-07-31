<?php
require_once __DIR__ . '/include/member_auth.php';

member_logout_session();
header('Location: member_login.php?return=reward');
exit;
