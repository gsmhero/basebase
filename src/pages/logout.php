<?php
if (is_post()) { csrf_check(); $_SESSION = []; session_destroy(); session_start(); flash('You have been logged out.'); }
redirect('/');
