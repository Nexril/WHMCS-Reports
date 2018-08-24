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
    'title' => 'Income Forecast by Product',
    'description' => 'Income forecast based on active products and cancellation requests, sorted by product.'
];

$productGroups = [];
$products = [];

$services = Capsule::connection()->table('tblhosting')
    ->select(['tblhosting.*', 'tblproducts.name AS product_name', 'tblproductgroups.name AS product_group_name'])
    ->leftJoin('tblcancelrequests', 'tblcancelrequests.relid', '=', 'tblhosting.id')
    ->leftJoin('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
    ->leftJoin('tblproductgroups', 'tblproductgroups.id', '=', 'tblproducts.gid')
    ->whereIn('tblhosting.domainstatus', ['Active', 'Pending'])
    ->where('tblcancelrequests.reason', '=', null)
    ->get();

foreach ($services as $service) {
    $monthly = $service->amount;

    switch ($service->billingcycle) {
        case 'Quarterly': $monthly /= 3; break;
        case 'Semi-Annually': $monthly /= 6; break;
        case 'Annually': $monthly /= 12; break;
    }

    $productGroups[$service->product_group_name] += $monthly;
    $products[$service->product_group_name][$service->product_name] += $monthly;
}

asort($productGroups);

$chartProducts = [];
$chartProductGroups = [['Group Name', 'Price']];

foreach ($products as $group => $product) {
    asort($product);
    $chartProducts[$group][] = ['Product Name', 'Price'];
    foreach ($product as $name => $amount) {
        $chartProducts[$group][] = [$name, $amount];
    }
}

foreach ($productGroups as $group => $amount) {
    $chartProductGroups[] = [$group, $amount];
}

$chartProductGroups = json_encode($chartProductGroups);

$chart->drawChart('Column', [], [], '450px');
$chartId = bin2hex(random_bytes(16));
$chartCode = <<<EOF
var data_{$chartId} = google.visualization.arrayToDataTable({$chartProductGroups});
var options_{$chartId} = { legend: { position: 'top' } };
var chart_{$chartId} = new google.visualization.PieChart(document.getElementById('chart_{$chartId}'));
chart_{$chartId}.draw(data_{$chartId}, options_{$chartId});
EOF;
$chartHtml = <<<EOF
<h1 style="text-align: center">Income by Product Group</h1>
<div id="chart_{$chartId}" style="width: 100%; height: 400px;"><div style="text-align: center;"><img src="images/loading.gif" /> Loading...</div></div>
EOF;

$chartHtml .= '<hr><div class="row">';

$cols = count($chartProducts);
$colWidth = 12 / $cols;
$colWidth = max(3, $colWidth);

foreach ($chartProducts as $group => $data) {
    $chartId = bin2hex(random_bytes(16));
    $productData = json_encode($data);
    $chartCode .= <<<EOF
var data_{$chartId} = google.visualization.arrayToDataTable({$productData});
var options_{$chartId} = {  };
var chart_{$chartId} = new google.visualization.PieChart(document.getElementById('chart_{$chartId}'));
chart_{$chartId}.draw(data_{$chartId}, options_{$chartId});
EOF;
    $chartHtml .= <<<EOF
<div class="col-md-{$colWidth}" style="text-align: center">  
<h1 style="text-align: center">{$group}</h1>
<div id="chart_{$chartId}" style="width: 100%; height: 375px;"><div style="text-align: center;"><img src="images/loading.gif" /> Loading...</div></div>
</div>
EOF;
}

$chartHtml .= '</div>';

$time = time();

$chart = <<<EOF
<script type="text/javascript">
    google.load('visualization', '1', {packages: ['corechart']});
    google.setOnLoadCallback(drawChart{$time});
    
    function drawChart{$time}() {
        {$chartCode}
    }
</script>
{$chartHtml}
EOF;

$reportdata["headertext"] = $chart;