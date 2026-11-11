<?php

// საიტის მყისიერი განახლება პროდუქტის სტატუსის ცვლილებისას
require_once __DIR__ . '/site_webhook.php';

// გაყიდული (WON) დილის სტადიას მხოლოდ ადმინი ცვლის
require_once __DIR__ . '/won_stage_lock.php';

// ბინის გარეშე დილი რეზერვაციაზე / ხელშეკრულებაზე / გაყიდულზე ვერ გადადის
require_once __DIR__ . '/apartment_stage_guard.php';

// ხელშეკრულების ეტაპი: 5 სამუშაო დღე, მერე დილი ბრუნდება დამუშავებაზე (agent)
require_once __DIR__ . '/contract_deadline.php';

// აბონირების გადასახადი (Dighomi / Green City): დარიცხვა და გადახდები ცალკე სიებში
require_once __DIR__ . '/subscription_fee.php';

// გადახდების სია (23): ღილაკი «Excel (რიცხვებით)» სიის თავში
require_once __DIR__ . '/payments_export_button.php';

// ჯავშნის ვადის გაგრძელება: ერთ რეზერვაციაზე მხოლოდ ერთხელ + გაგრძელებული ჯავშნის ვადის ამოწურვა (agent)
require_once __DIR__ . '/reservation_extension.php';
