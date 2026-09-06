<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Staff;
use App\Models\Branch;
use App\Models\Customer;
use Illuminate\Support\Facades\Hash;

class StaffSeeder extends Seeder
{
    public function run(): void
    {
        // 1. إنشاء فرع ببيانات حقيقية في الرياض (إحداثيات دقيقة)
        // تم استخدام updateOrCreate لتجنب تكرار الفرع إذا تم تشغيل الأمر أكثر من مرة
        $branch = Branch::updateOrCreate(
            ['name_ar' => 'فرع الرياض الرئيسي'],
            [
                'name_ar' => 'فرع الرياض الرئيسي',
                'name_en' => 'Riyadh Main Branch',
                'lat' => 24.7135548, // خط العرض (الرياض - العليا)
                'lng' => 46.6752957, // خط الطول (الرياض - العليا)
                'default_radius_meters' => 500,
                'is_active' => true,
            ]
        );

        // 2. إنشاء حساب المدير (Manager)
        Staff::updateOrCreate(
            ['username' => 'manager'],
            [
                'name' => 'أحمد المدير',
                'phone' => '0501111111',
                'username' => 'manager',
                'password' => Hash::make('ZenCafe@M@n@ger#2026$Secure'),
                'role' => 'manager',
                'is_active' => true,
                'branch_id' => $branch->id,
            ]
        );

        // 3. إنشاء حساب الكاشير (Cashier)
        Staff::updateOrCreate(
            ['username' => 'cashier'],
            [
                'name' => 'خالد الكاشير',
                'phone' => '0502222222',
                'username' => 'cashier',
                'password' => Hash::make('ZenCafe@C@shier#2026$Secure'),
                'role' => 'cashier',
                'is_active' => true,
                'branch_id' => $branch->id,
            ]
        );

        // 4. إنشاء حساب المطبخ (Kitchen)
        Staff::updateOrCreate(
            ['username' => 'kitchen'],
            [
                'name' => 'سعيد الشيف',
                'phone' => '0503333333',
                'username' => 'kitchen',
                'password' => Hash::make('ZenCafe@K!tchen#2026$Secure'),
                'role' => 'kitchen',
                'is_active' => true,
                'branch_id' => $branch->id,
            ]
        );

        // 5. إنشاء حساب عميل (Customer) - بدون إيميل كما هو محدد في الـ Migration
        Customer::updateOrCreate(
            ['phone' => '0559999999'],
            [
                'name' => 'عميل تجريبي',
                'email' => null, 
                'phone' => '0559999999',
                'password' => Hash::make('ZenCafe@Cust0mer#2026$Secure'),
            ]
        );

        // طباعة النتائج في التيرمنال لتأكيد النجاح
        $this->command->info('✅ تم ملء قاعدة البيانات ببيانات سعودية بنجاح!');
        $this->command->info('🏢 الفرع: ' . $branch->name_ar . ' | الإحداثيات: ' . $branch->lat . ',' . $branch->lng);
        $this->command->info('--------------------------------------------------');
        $this->command->info('👨‍💼 Manager  | username: manager   | password: ZenCafe@M@n@ger#2026$Secure');
        $this->command->info('💵 Cashier  | username: cashier   | password: ZenCafe@C@shier#2026$Secure');
        $this->command->info('👨‍🍳 Kitchen  | username: kitchen   | password: ZenCafe@K!tchen#2026$Secure');
        $this->command->info('👤 Customer  | phone: 0559999999   | password: ZenCafe@Cust0mer#2026$Secure');
    }
}