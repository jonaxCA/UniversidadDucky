<?php
require_once 'includes/auth.php';
destroySession();
header('Location: index.php?bye=1');
exit;
