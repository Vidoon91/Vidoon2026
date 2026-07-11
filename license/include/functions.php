<?php
function generate_license_key($length = 16) {
    return strtoupper(bin2hex(random_bytes($length/2)));
}

function is_expired($date) {
    return $date && strtotime($date) < time();
}
?>
