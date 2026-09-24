# HARDEX bila Warehouse: ukaguzi wa mwongozo

Tarehe: 23 Septemba 2026. Huu ni ukaguzi wa msimbo wa programu; haukubadilisha mantiki au data.

## Mtiririko uliothibitishwa

- `InventorySettings::directStockInAllowed()` inahitaji `enable_warehouse = false` na `allow_direct_stock_in = true`.
- Direct Stock In inapokea stoo moja kwa moja kwenye Dispensing Area kupitia `receivingLocation()`. Fomu haina supplier au purchase order.
- POS hukata stoo kwenye eneo la kuuza lililoruhusiwa na hukataa kiasi kinachozidi salio.
- Menyu na routes za Purchases, Suppliers, purchase reports, supplier payments, Stock Transfers na Stock Transfer Note zimefungwa kwa `warehouse.enabled`.

## Limitation iliyobainika

`resources/views/livewire/reports/customers.blade.php` inaonyesha safu ya **Overdue** lakini inaandika `money(0)` kwa kila mteja. Hivyo thamani hiyo haiwakilishi deni lililochelewa. Mwongozo umeeleza hili bila kubadili programu.

## Mipaka ya uthibitisho

Ukaguzi huu umetumia routes, sidebar, fomu na huduma za msimbo. Haujaingia kwenye akaunti ya HARDEX inayotumika, kwa hiyo hali halisi ya `allow_direct_stock_in`, ruhusa za kampuni, kifaa cha WhatsApp na picha tano za skrini zimetolewa kutoka programu ya karibu, lakini mwonekano wa deployment nyingine haujathibitishwa. Mwongozo unahusu usanidi uliotajwa: Warehouse imezimwa na Direct Stock In imewashwa.
