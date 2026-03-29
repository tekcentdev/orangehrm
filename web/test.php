<?php echo session_save_path(); ?>



<?php 

session_name('orangehrm');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

echo session_id(); 

?>