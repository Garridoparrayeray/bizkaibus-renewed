<?php
$pdo = new PDO('sqlite:../data/gasolinera.sqlite');
$stations = $pdo->query('SELECT COUNT(*) FROM stations')->fetchColumn();
$history = $pdo->query('SELECT COUNT(*) FROM price_history')->fetchColumn();
echo "Stations: $stations\nHistory: $history\n";
