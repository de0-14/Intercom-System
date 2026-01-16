<?php
require_once 'conn.php';


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
    <link rel="stylesheet" type="text/css" href="style.css">
</head>
<body>
    <div class="header">
        <div class="logo">
            <img src="hospitalLogo.png" alt="Hospital Logo">
            <span>DAVAO REGIONAL MEDICAL CENTER</span>
        </div>
        <div>
            <ul class="nav">
            <li id="home"><a href="homepage.php"> Home page </a></li>
            <?php if (isLoggedIn()) : ?>
            <li id="create"><a href="createpage.php"> Create page</a></li>
			<li id="edit"><a href="editpage.php"> Edit/Remove page </a></li>
			<li id="profile"><a href="profilepage.php"> Profile page </a></li>
				<li id="logout"><a href="logout.php"> Logout (<?php echo html_entity_decode(getUserName()); ?>)</a></li>
			<?php else: ?>
				<li id="logout"><a href="login.php"> Login </a></li>
			<?php endif; ?>
        </ul>
        </div>
    </div>
</body>
</html>