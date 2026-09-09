<?php

// Task 1
$myname = "Husanpreet";
echo "<h3>Task 1</h3>";
echo "Length: " . strlen($myname);

echo "<hr>";

// Task 2
echo "<h3>Task 2</h3>";
$text = "The quick brown dog jumped over the lazy cow.";
echo wordwrap($text, 10, "<br>");


echo "<hr>";

// Task 3
echo "<h3>Task 3</h3>";

$a = "apple";
$b = "banana";

if (!strcmp($a, $b)) {
    print $a;
} else {
    print $b;
}

?>
