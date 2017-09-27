<?php

use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use bigdropinc\cashier\Cashier;

/* @var $user \yii\db\ActiveRecord */
/* @var $invoice \bigdropinc\cashier\Invoice */
/* @var $subscription \bigdropinc\cashier\InvoiceItem */
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">

    <title>Invoice</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            background: #fff none;
            font-size: 12px;
        }

        address {
            margin-top: 15px;
        }

        h2 {
            font-size: 28px;
            color: #cccccc;
        }

        .container {
            padding-top: 30px;
        }

        .invoice-head td {
            padding: 0 8px;
        }

        .table th {
            vertical-align: bottom;
            font-weight: bold;
            padding: 8px;
            line-height: 20px;
            text-align: left;
        }

        .table td {
            padding: 8px;
            line-height: 20px;
            text-align: left;
            vertical-align: top;
            border-top: 1px solid #dddddd;
        }
    </style>
</head>

<body>
<div class="container">
    <table style="margin-left: auto; margin-right: auto" width="550">
        <tr>
            <td width="160">
                &nbsp;
            </td>

            <!-- Organization Name / Image -->
            <td align="right">
                <strong><?php echo isset($header) ? $header : $vendor; ?></strong>
            </td>
        </tr>
        <tr valign="top">
            <td style="font-size:28px;color:#cccccc;">
                Receipt
            </td>

            <!-- Organization Name / Date -->
            <td>
                <br><br>
                <strong>To:</strong> <?php echo ArrayHelper::getValue($user, 'email', 'name'); ?>
                <br>
                <strong>Date:</strong> <?php echo $invoice->date()->toFormattedDateString(); ?>
            </td>
        </tr>
        <tr valign="top">
            <!-- Organization Details -->
            <td style="font-size:9px;">
                <?php echo $vendor; ?><br>
                <?php if (isset($street)): ?>
                    <?php echo $street; ?><br>
                <?php endif; ?>
                <?php if (isset($location)): ?>
                    <?php echo $location; ?><br>
                <?php endif; ?>
                <?php if (isset($phone)): ?>
                    <strong>T</strong> <?php echo $phone; ?><br>
                <?php endif; ?>
                <?php if (isset($url)): ?>
                    <?php echo Html::a($url, $url); ?>
                <?php endif; ?>
            </td>
            <td>
                <!-- Invoice Info -->
                <p>
                    <strong>Product:</strong> <?php echo $product; ?><br>
                    <strong>Invoice Number:</strong> <?php echo isset($id) ? $id : $invoice->id; ?><br>
                </p>

                <!-- Extra / VAT Information -->
                <?php if (isset($vat)): ?>
                    <p>
                        <?php echo $vat; ?>
                    </p>
                <?php endif; ?>

                <br><br>

                <!-- Invoice Table -->
                <table width="100%" class="table" border="0">
                    <tr>
                        <th align="left">Description</th>
                        <th align="right">Date</th>
                        <th align="right">Amount</th>
                    </tr>

                    <!-- Existing Balance -->
                    <tr>
                        <td>Starting Balance</td>
                        <td>&nbsp;</td>
                        <td><?php echo $invoice->startingBalance(); ?></td>
                    </tr>

                    <!-- Display The Invoice Items -->
                    <?php foreach ($invoice->invoiceItems() as $item): ?>
                        <tr>
                            <td colspan="2"><?php echo $item->description; ?></td>
                            <td><?php echo $item->total(); ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <!-- Display The Subscriptions -->
                    <?php foreach ($invoice->subscriptions() as $subscription): ?>
                        <tr>
                            <td>Subscription (<?php echo $subscription->quantity; ?>)</td>
                            <td><?php echo $subscription->startDateAsCarbon()->formatLocalized('%B %e, %Y'); ?>
                                - <?php echo $subscription->endDateAsCarbon()->formatLocalized('%B %e, %Y'); ?></td>
                            <td><?php echo $subscription->total(); ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <!-- Display The Discount -->
                    <?php if ($invoice->hasDiscount()): ?>
                        <tr>
                            <?php if ($invoice->discountIsPercentage()): ?>
                                <td><?php echo $invoice->coupon(); ?> (<?php echo $invoice->percentOff(); ?>% Off)</td>
                            <?php else: ?>
                                <td><?php echo $invoice->coupon(); ?> (<?php echo $invoice->amountOff(); ?>% Off)</td>
                            <?php endif; ?>
                            <td>&nbsp;</td>
                            <td>-<?php echo $invoice->discount(); ?></td>
                        </tr>
                    <?php endif; ?>

                    <!-- Display The Tax Amount -->
                    <?php if ($invoice->tax_percent): ?>
                        <tr>
                            <td>Tax (<?php echo $invoice->tax_percent; ?>%)</td>
                            <td>&nbsp;</td>
                            <td><?php echo Cashier::formatAmount($invoice->tax); ?></td>
                        </tr>
                    <?php endif; ?>

                    <!-- Display The Final Total -->
                    <tr style="border-top:2px solid #000;">
                        <td>&nbsp;</td>
                        <td style="text-align: right;"><strong>Total</strong></td>
                        <td><strong><?php echo $invoice->total(); ?></strong></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</div>
</body>
</html>
