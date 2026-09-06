# Cafe Zen — QR Menu & Ordering API

باك اند Laravel API لموديول "منيو الـ QR والطلب الداخلي" الخاص بمنصة **Cafe Zen (زين كافيه)**، بيغطي رحلة الزبون كاملة من مسح الـ QR على الطاولة لحد استلام الطلب من الكاشير، بالإضافة للوحتي الكاشير والمطبخ لإدارة الطلبات لحظيًا.

> هذا الموديول جزء من منصة Cafe Zen الأشمل (اللي بتغطي كمان: الطلب المسبق، متجر الإيكومرس، الولاء، الفوترة الإلكترونية ZATCA). المستند ده بيغطي **موديول المنيو والطلب الداخلي فقط**.

---

## المحتويات

- [نظرة عامة على المعمارية](#نظرة-عامة-على-المعمارية)
- [المتطلبات](#المتطلبات)
- [التثبيت والإعداد](#التثبيت-والإعداد)
- [إعداد الـ Guards (Staff / Customer)](#إعداد-الـ-guards-staff--customer)
- [تسجيل الـ Middleware والـ Routes](#تسجيل-الـ-middleware-والـ-routes)
- [هيكل قاعدة البيانات](#هيكل-قاعدة-البيانات)
- [دورة حياة الطلب](#دورة-حياة-الطلب)
- [مرجع الـ API](#مرجع-الـ-api)
- [الـ Broadcasting (لوحات الكاشير والمطبخ Real-time)](#الـ-broadcasting)
- [بيانات وسكريبت الاختبار](#بيانات-وسكريبت-الاختبار)
- [قرارات معمارية مهمة](#قرارات-معمارية-مهمة)
- [نقاط للتطوير المستقبلي](#نقاط-للتطوير-المستقبلي)

---

## نظرة عامة على المعمارية

الموديول بيتكون من 3 طبقات مستخدمين مختلفة، كل واحدة بيها Guard منفصل:

| المستخدم | الـ Guard | طريقة الدخول | الاستخدام |
|---|---|---|---|
| **الزبون** | `customer` (Sanctum) | حساب بيتعمل تلقائيًا أول أوردر (باسورد عشوائي) | فتح المنيو، عمل أوردر، متابعة الحالة |
| **الكاشير/المطبخ** | `staff` (Sanctum) | `username` + `password` يدويين | قبول/رفض الطلبات، تحديث حالة التحضير، تحصيل الدفع |
| **الأدمن** | `web`/`sanctum` العادي | (خارج نطاق هذا الموديول) | إدارة المنيو والفروع والموظفين |

### تدفق فتح المنيو (Access Flow)

```
مسح QR
   │
   ▼
GET /menu/{token}  ──► بيانات الفرع الأساسية
   │
   ▼
POST /menu/{token}/verify-location (lat, lng)
   │                       │
   │ نجاح (داخل النطاق)      │ فشل / المستخدم رفض المشاركة
   ▼                       ▼
access_token         POST /menu/{token}/manual-access (code من الجرسون)
   │                       │
   └───────────┬───────────┘
               ▼
   GET /menu/{token}/items          (Header: X-Menu-Access-Token)
   POST /menu/{token}/orders        (Header: X-Menu-Access-Token)
```

الـ `access_token` ده **مش** Sanctum token — هو توكين مؤقت (30 دقيقة) متخزن في الـ Cache، مربوط بالـ QR code نفسه، وغرضه الوحيد إثبات إن الزبون عدّى خطوة التحقق من الموقع قبل ما يشوف المنيو أو يعمل أوردر.

---

## المتطلبات

- PHP **8.3+**
- Laravel **13.x**
- MySQL 8+ (أو أي قاعدة بيانات متوافقة مع Laravel Schema Builder)
- Laravel Sanctum
- (اختياري لكن موصى بيه) Laravel Reverb أو Pusher لتفعيل الـ Broadcasting الفعلي للوحتي الكاشير/المطبخ

---

## التثبيت والإعداد

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

انسخ كل الملفات المرفقة لمكانها المطابق في هيكل المشروع:

```
app/Models/...
app/Services/...
app/Events/...
app/Exceptions/...
app/Http/Controllers/Api/...
app/Http/Middleware/...
app/Http/Requests/...
routes/qr_menu_ordering_api.php
database/migrations/...
```

---

## إعداد الـ Guards (Staff / Customer)

ضيف في `config/auth.php`:

```php
'guards' => [
    // ... الموجود بالفعل
    'staff' => [
        'driver' => 'sanctum',
        'provider' => 'staff',
    ],
    'customer' => [
        'driver' => 'sanctum',
        'provider' => 'customers',
    ],
],

'providers' => [
    // ... الموجود بالفعل
    'staff' => [
        'driver' => 'eloquent',
        'model' => App\Models\Staff::class,
    ],
    'customers' => [
        'driver' => 'eloquent',
        'model' => App\Models\Customer::class,
    ],
],
```

---

## تسجيل الـ Middleware والـ Routes

في `bootstrap/app.php`:

```php
use App\Http\Middleware\EnsureMenuAccessVerified;
use App\Http\Middleware\EnsureStaffRole;

->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'menu.access.verified' => EnsureMenuAccessVerified::class,
        'staff.role' => EnsureStaffRole::class,
    ]);
})
```

وتحميل ملف الراوتس في `routes/api.php` (أو داخل `then:` بتاع `withRouting`):

```php
Route::prefix('api')->group(base_path('routes/qr_menu_ordering_api.php'));
```

---

## هيكل قاعدة البيانات

**منيو الـ QR:** `branches` → `tables` ⇄ `qr_codes` → `menu_categories` → `menu_items` ⇄ `menu_item_branch` (pivot) → `menu_options` → `menu_option_values`، بالإضافة لـ `menu_access_logs` لتسجيل كل محاولة فتح.

**الطلب والكاشير والمطبخ:** `staff` (خاص بكل فرع) + `customers` (حساب فعلي بباسورد عشوائي) → `orders` → `order_items` → `order_item_options`، بالإضافة لـ `order_status_logs` (تتبّع كل تغيير حالة) و`order_payments` (تحصيل الكاشير اليدوي).

17 جدول إجمالًا — التفاصيل الكاملة لكل عمود موجودة في ملفات الـ Migrations نفسها.

---

## دورة حياة الطلب

```
pending ──accept──► accepted ──► preparing ──► ready ──► served ──payment──► paid
   │                    │             │
   └──reject──►rejected └──cancel──► cancelled
```

الانتقالات المسموحة معرّفة مركزيًا في `Order::allowedTransitions()`، وأي محاولة انتقال غير مسموح بيها بترمي `InvalidOrderTransitionException`.

---

## مرجع الـ API

### Auth

| Method | Endpoint | الوصف |
|---|---|---|
| POST | `/api/customer/login` | دخول العميل (`phone` + `password`) |
| POST | `/api/staff/login` | دخول الموظف (`username` + `password`) |

### Menu (Public)

| Method | Endpoint | الحماية | الوصف |
|---|---|---|---|
| GET | `/api/menu/{token}` | - | بيانات الفرع الأساسية |
| POST | `/api/menu/{token}/verify-location` | - | تحقق جيوفنسينج (`lat`, `lng`) |
| POST | `/api/menu/{token}/manual-access` | - | الكود اليدوي (`code`) |
| GET | `/api/menu/{token}/items` | `X-Menu-Access-Token` | المنيو الكامل |
| POST | `/api/menu/{token}/orders` | `X-Menu-Access-Token` | إنشاء أوردر |
| GET | `/api/menu/{token}/orders/{order}` | `X-Menu-Access-Token` | متابعة حالة أوردر |

### Cashier (`auth:staff` + role `cashier`/`manager`)

| Method | Endpoint | الوصف |
|---|---|---|
| GET | `/api/cashier/orders?status=pending,accepted` | قائمة الطلبات |
| POST | `/api/cashier/orders/{order}/accept` | قبول الطلب |
| POST | `/api/cashier/orders/{order}/reject` | رفض الطلب |
| POST | `/api/cashier/orders/{order}/served` | تعليم "تم التقديم" |
| POST | `/api/cashier/orders/{order}/payment` | تسجيل الدفع (`method`, `amount`) |

### Kitchen (`auth:staff` + role `kitchen`/`manager`)

| Method | Endpoint | الوصف |
|---|---|---|
| GET | `/api/kitchen/orders?status=accepted,preparing` | قائمة الطلبات |
| POST | `/api/kitchen/orders/{order}/start-preparing` | بدء التحضير |
| POST | `/api/kitchen/orders/{order}/mark-ready` | تعليم "جاهز" |

كل مسارات الـ Cashier والـ Kitchen بترسل `Authorization: Bearer {sanctum_token}`.

---

## الـ Broadcasting

`OrderCreated` و`OrderStatusUpdated` بيبثوا على private channels خاصة بكل فرع:

- `branch.{id}.cashier` — كل تحديث
- `branch.{id}.kitchen` — بس لما الحالة تبقى `accepted`, `preparing`, `ready`

محتاج `BROADCAST_CONNECTION` مظبوط في `.env` (Reverb/Pusher) عشان الـ Events دي تتبث فعليًا.

---

## بيانات وسكريبت الاختبار

- `test_data.sql` — بيانات تجريبية كاملة (فرع، طاولة، QR codes، موظفين، صنف منيو) بـ SQL مباشر، من غير Seeders.
- `test_flow.sh` — سكريبت curl بيجري الـ flow كامل من فتح المنيو لحد تحصيل الدفع.

---

## قرارات معمارية مهمة

- **مفيش Redis Cache للمنيو حاليًا** — تم تأجيله عمدًا لحد ما يظهر حمل فعلي يستدعيه.
- **`radius_override`** على مستوى الـ QR نفسه بيتفوّق على `default_radius_meters` بتاع الفرع — مرونة لحالات استثنائية (طاولة قريبة من حدود الفرع مثلاً).
- **الـ Fallback بالكود اليدوي** بديل عن المنع التام لما الزبون يرفض مشاركة موقعه — قرار منتج مقصود لتقليل الاحتكاك.
- **حساب العميل التلقائي**: الباسورد بيتولد عشوائيًا (حروف + أرقام) ويترجع في response إنشاء الطلب لحد ما الفرونت يطبعه للزبون — العميل مبيدخلش باسورد يدوي أبدًا في هذه المرحلة.
- **الدفع يدوي بالكامل** (كاش/بطاقة) من غير بوابة دفع أونلاين، وبيتحقق من تطابق المبلغ مع `total_amount` قبل ما يقفل الطلب.

---

## نقاط للتطوير المستقبلي

- كاش Redis للمنيو المفلتر لو زاد الحمل.
- دمج بوابة دفع أونلاين اختيارية.
- نظام تتبّع طلبات سابقة للعميل عبر حساب `customer` بعد أول أوردر.
- توسعة `staff.role` لصلاحيات أدق (مثلاً: مين يقدر يرفض طلب بعد قبوله).
