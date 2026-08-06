<?php
// Legacy client-token links no longer participate in the free-credit flow.
header('Cache-Control: no-store');
header('Location: index.php');
exit;
