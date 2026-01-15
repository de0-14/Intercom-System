<?php
require_once'conn.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create page</title>
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
    <div class="content">
        <ul class="choice">
			<li id="create_division"><a href="createpage.php"> Create Divsion </a></li>
			<li id="create_department"><a href="create_department.php"> Create Department </a></li>
			<li id="create_unit"><a href="create_unit.php"> Create Unit </a></li>
            <li id="create_office"><a href="create_office.php"> Create Office </a></li>
		</ul>
    </div>
</body>
</html>