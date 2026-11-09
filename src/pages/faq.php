<?php
$faq = [
    ['What is basebase?', 'A multivendor marketplace with dropshipping. Suppliers list products, resellers and bloggers collect them into their own shops, and customers order. The supplier ships; the reseller earns a commission.'],
    ['How do I start selling without stock?', 'Register as a Reseller / blogger, open the catalog and press “Collect” on any product. Share the product, a set or your shop page. Orders placed through your link bring you a commission.'],
    ['How do I become a supplier?', 'Register as a Supplier, add your products with price, stock and the commission you pay to resellers. You receive orders in the Orders manager and update statuses as you ship.'],
    ['When do I get paid?', 'Earnings are credited to your wallet when an order item is marked as Delivered. Request a payout from Profile & wallet.'],
    ['What are sets and groups?', 'A set bundles several collected products at a special price with its own link. Groups are labels that let you share a themed part of your showcase.'],
    ['Which payout types are supported?', 'Self-employed, individual entrepreneur (ИП) and company. Fill in your details in Profile → Personal data.'],
    ['How are orders delivered?', 'Suppliers choose the carrier (for example CDEK or OZON Shipping). You can track every status from Pending to Delivered in your cabinet.'],
];
layout('FAQ', function () use ($faq) { ?>
<div class="wrap" style="max-width:820px">
  <div class="page-h"><div class="crumbs"><a href="/">Home</a> / FAQ</div><h1>Frequently asked questions</h1></div>
  <div class="faq" style="margin-top:24px"><?php foreach ($faq as $i => [$qq, $a]): ?><details <?= $i === 0 ? 'open' : '' ?>><summary><?= e($qq) ?></summary><p><?= e($a) ?></p></details><?php endforeach; ?></div>
  <p class="muted">Still have a question? Create an account and write to us in the Messenger.</p>
</div>
<?php });
