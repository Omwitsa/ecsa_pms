<?php
require __DIR__ . '/includes/bootstrap.php';
redirect(current_user() ? home_url() : 'login.php');
