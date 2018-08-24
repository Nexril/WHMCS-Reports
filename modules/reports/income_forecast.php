<?php

use Illuminate\Database\Capsule\Manager as Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function fmt($value) {
    return $value == 0 ? '-' : money_format('$%i', $value);
}

$totals = [];

$reportdata = [
    'title' => 'Income Forecast',
    'description' => 'Income forecast based on active products and cancellation requests.',
    'tableheadings' => [
        'Month', 'Monthly', 'Quarterly', 'Semi-Annual', 'Annual', 'Total'
    ]
];

$products = Capsule::connection()->table('tblhosting')
    ->select(['tblhosting.*'])
    ->leftJoin('tblcancelrequests', 'tblcancelrequests.relid', '=', 'tblhosting.id')
    ->whereIn('tblhosting.domainstatus', ['Active', 'Pending'])
    ->where('tblcancelrequests.reason', '=', null)
    ->get();

foreach ($products as $product) {
    $cycle = 1;
    switch ($product->billingcycle) {
        case 'Quarterly': $cycle = 3; break;
        case 'Semi-Annually': $cycle = 6; break;
        case 'Annually': $cycle = 12; break;
    }

    $nextDueMonth = explode('-', $product->nextduedate)[1];
    $nextDueYear = explode('-', $product->nextduedate)[0];

    for ($i = 0; $i <= 12; $i += $cycle) {
        $newDate = mktime(0, 0, 0, $nextDueMonth + $i, 1, $nextDueYear);
        $totals[date('Y', $newDate)][date('m', $newDate)][$cycle] += $product->amount;
    }
}


$table = [];

for ($i = 0; $i <= 12; $i++) {
    $newDate = mktime(0, 0, 0, date('m') + $i, 1, date('Y'));
    $table[date('Y', $newDate)][] = date('m', $newDate);
}

$totalIncome = 0;
$chartData = [];
$chartData[] = [
    'Month', 'Monthly', 'Quarterly', 'Semi-Annual', 'Annual', ['role' =>  'annotation']
];

foreach ($table as $year => $months) {
    foreach ($months as $month) {
        $monthly = $totals[$year][$month][1] + $totals[$year][$month][3] + $totals[$year][$month][6] + $totals[$year][$month][12];
        $totalIncome += $monthly;

        $reportdata['tablevalues'][] = [
            date('F Y', mktime(0, 0, 0, $month, 1, $year)),
            fmt($totals[$year][$month][1]),
            fmt($totals[$year][$month][3]),
            fmt($totals[$year][$month][6]),
            fmt($totals[$year][$month][12]),
            fmt($monthly)
        ];
        $chartData[] = [
            date('M Y', mktime(0, 0, 0, $month, 1, $year)),
            floatval($totals[$year][$month][1]),
            floatval($totals[$year][$month][3]),
            floatval($totals[$year][$month][6]),
            floatval($totals[$year][$month][12]),
            '$' . intval($monthly)
        ];
    }
}

$chartData = json_encode($chartData);

$cache = time();

$chart->drawChart('Column', [], [], '450px');
$chart = <<<EOF
<div id="chartcont{$cache}" style="width: 100%; height: 450px;"><div style="padding-top: 215px; text-align: center;"><img src="images/loading.gif" /> Loading...</div></div>
<script type="text/javascript">
    google.load('visualization', '1', {packages: ['corechart']});
    google.setOnLoadCallback(drawChart{$cache});
    
    function drawChart{$cache}() {
        var data = google.visualization.arrayToDataTable({$chartData});
        var options = {
            legend: {
                position: 'top'
            },
            isStacked: true
        };
        
        var chart = new google.visualization.ColumnChart(document.getElementById('chartcont{$cache}'));
        chart.draw(data, options);
    }
</script>
EOF;

$reportdata["headertext"] = $chart;