<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $divisionName = $_POST['name'];
    $numbers = $_POST['numbers'];
    
    // Process each number
    foreach ($numbers as $numberData) {
        $phoneNumber = $numberData['number'];
        $types = $numberData['types']; // Array of selected types
        
        // Save to database or process as needed
        // $types will contain values like ['SMS', 'Landline'] or ['Intercom'] etc.
    }
    
    // Redirect or show success message
}
?>