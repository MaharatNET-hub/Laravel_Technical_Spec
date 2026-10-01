<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\V2\Project;
use App\Models\V2\Service;
use App\Models\V2\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * v2 content: the admin account and the company profile (sample content, all editable from the
 * dashboard). Safe to run again: it only fills what is missing.
 */
class V2Seeder extends Seeder
{
    public function run(): void
    {
        $this->admin();
        $this->settings();
        if (! Service::query()->exists()) {
            $this->services();
        }
        if (! Project::query()->exists()) {
            $this->projects();
        }
    }

    private function admin(): void
    {
        $email = (string) config('v2.admin_email');
        $password = (string) config('v2.admin_password');
        $user = User::where('email', $email)->first();
        if ($user) {
            if ($password !== '' && ! Hash::check($password, $user->password)) {
                $user->update(['password' => Hash::make($password)]); // the env value is the source of truth
            }

            return;
        }
        $generated = $password === '';
        if ($generated) {
            $password = Str::password(16, symbols: false);
        }
        User::create(['name' => 'Administrator', 'email' => $email, 'password' => Hash::make($password)]);
        if ($generated) {
            // no V2_ADMIN_PASSWORD set: never fall back to a known default
            @mkdir(storage_path('app/v2'), 0775, true);
            file_put_contents(storage_path('app/v2/ADMIN-PASSWORD.txt'), "Email: $email\nPassword: $password\n\nSet V2_ADMIN_PASSWORD in .env (or the host's environment) to choose your own, then delete this file.\n");
            $msg = "v2 admin created: $email / $password (also saved in storage/app/v2/ADMIN-PASSWORD.txt). Set V2_ADMIN_PASSWORD to choose your own.";
            $this->command?->warn($msg);
            error_log($msg);
        }
    }

    private function settings(): void
    {
        $defaults = [
            'company' => [
                'name_en' => 'Atlas Engineering Consultants', 'name_ar' => 'أطلس للاستشارات الهندسية',
                'tagline_en' => 'MEP design, supervision and technical review for projects across the region.',
                'tagline_ar' => 'تصميم وإشراف ومراجعة فنية للأعمال الكهروميكانيكية في مشاريع المنطقة.',
                'about_en' => "We are a multidisciplinary engineering consultancy specialising in mechanical, electrical and plumbing (MEP) systems.\n\nOur engineers review contractor submittals, supervise construction and make sure every installed system meets the project specification — on time and with full traceability.",
                'about_ar' => "نحن مكتب استشارات هندسية متعدد التخصصات، متخصص في الأنظمة الكهروميكانيكية (الكهرباء والميكانيك والصحية).\n\nيراجع مهندسونا تقديمات المقاولين، ويشرفون على التنفيذ، ويتأكدون أن كل نظام يُركَّب مطابق لمواصفات المشروع، في الوقت المحدد ومع توثيق كامل.",
                'mission_en' => 'Faster, traceable technical decisions — every comment linked to the clause it comes from.',
                'mission_ar' => 'قرارات فنية أسرع وقابلة للتتبّع، وكل ملاحظة مرتبطة بالبند الذي جاءت منه.',
                'founded' => 2009,
            ],
            'contact' => [
                'email' => 'info@example.com', 'phone' => '+971 4 000 0000', 'whatsapp' => '',
                'address_en' => 'Business Bay, Dubai, United Arab Emirates', 'address_ar' => 'الخليج التجاري، دبي، الإمارات العربية المتحدة',
                'hours_en' => 'Sun–Thu, 8:00–17:00', 'hours_ar' => 'الأحد – الخميس، 8:00 – 17:00',
                'map' => '', 'linkedin' => '',
            ],
            'stats' => [
                ['value' => '15+', 'label_en' => 'Years of practice', 'label_ar' => 'سنة خبرة'],
                ['value' => '240', 'label_en' => 'Projects delivered', 'label_ar' => 'مشروع منجز'],
                ['value' => '3,800', 'label_en' => 'Submittals reviewed', 'label_ar' => 'تقديم تمت مراجعته'],
                ['value' => '45', 'label_en' => 'Engineers', 'label_ar' => 'مهندس'],
            ],
            'review' => ['hide_default' => true, 'notify_email' => '', 'auto_analyse' => true],
        ];
        foreach ($defaults as $k => $v) {
            if (Setting::find($k) === null) {
                Setting::put($k, $v);
            }
        }
    }

    private function services(): void
    {
        $rows = [
            ['bolt', 'Electrical design', 'التصميم الكهربائي', 'LV distribution, lighting, power, earthing and emergency systems designed to IEC and local authority rules.', 'شبكات التوزيع منخفضة الجهد، والإنارة، والقوى، والتأريض، وأنظمة الطوارئ، وفق معايير IEC واشتراطات الجهات المحلية.'],
            ['fan', 'HVAC & mechanical', 'التكييف والأعمال الميكانيكية', 'Cooling, ventilation, smoke control and energy-efficient plant selection.', 'التبريد والتهوية والتحكم بالدخان، واختيار معدات موفّرة للطاقة.'],
            ['drop', 'Plumbing & fire protection', 'الأعمال الصحية والحماية من الحريق', 'Water supply, drainage, sprinklers and fire-fighting systems.', 'تغذية المياه والصرف ورشاشات الحريق وأنظمة الإطفاء.'],
            ['clipboard', 'Submittal review', 'مراجعة التقديمات الفنية', 'Contractor and vendor submittals checked clause by clause against the specification, with a marked-up return file.', 'مراجعة تقديمات المقاولين والموردين بنداً بنداً مقابل المواصفات، مع ملف مُرجَع عليه التأشيرات.'],
            ['helmet', 'Site supervision', 'الإشراف على التنفيذ', 'Inspections, testing & commissioning witness, snag lists and handover.', 'التفتيش، وحضور الفحص والتشغيل، وقوائم الملاحظات، والتسليم.'],
            ['chart', 'Energy & sustainability', 'الطاقة والاستدامة', 'Load studies, energy audits and green-building compliance.', 'دراسات الأحمال، وتدقيق الطاقة، ومطابقة اشتراطات المباني الخضراء.'],
        ];
        foreach ($rows as $i => [$icon, $ten, $tar, $sen, $sar]) {
            Service::create(['icon' => $icon, 'title_en' => $ten, 'title_ar' => $tar, 'summary_en' => $sen, 'summary_ar' => $sar, 'sort' => $i]);
        }
    }

    private function projects(): void
    {
        $rows = [
            ['residential-towers', 'Residential Towers A & B', 'برجان سكنيان A و B', 'Residential', 'Dubai', 'دبي', 2025, '#1f4fbf', true,
                'Two towers (B+G+23 and B+G+20) — full MEP supervision and submittal review for LV switchgear, busways and life-safety systems.',
                'برجان (قبو + أرضي + 23 و20 طابقاً): إشراف كامل على الأعمال الكهروميكانيكية ومراجعة تقديمات لوحات الجهد المنخفض ومجاري القضبان وأنظمة السلامة.'],
            ['hospital-retrofit', 'Hospital MEP Retrofit', 'تحديث الأنظمة الكهروميكانيكية لمستشفى', 'Healthcare', 'Abu Dhabi', 'أبوظبي', 2024, '#0f766e', true,
                'Phased upgrade of a 300-bed hospital while fully operational: new standby power, medical-gas alarms and HVAC controls.',
                'تحديث على مراحل لمستشفى بسعة 300 سرير وهو قيد التشغيل: طاقة احتياطية جديدة، وإنذارات الغازات الطبية، وأنظمة تحكم التكييف.'],
            ['logistics-hub', 'Logistics Hub', 'مركز لوجستي', 'Industrial', 'Jebel Ali', 'جبل علي', 2024, '#b45309', true,
                '120,000 m² warehouse with high-bay lighting, sprinkler systems and 4 MW of rooftop solar.',
                'مستودع بمساحة 120,000 م² مع إنارة للأسقف العالية، وأنظمة رشاشات، و4 ميغاواط من الطاقة الشمسية على السطح.'],
            ['school-campus', 'School Campus', 'حرم مدرسي', 'Education', 'Sharjah', 'الشارقة', 2023, '#7c3aed', false,
                'Design and supervision for a K-12 campus of five buildings, targeting a green-building rating.',
                'تصميم وإشراف لحرم مدرسي من خمسة مبانٍ، مع استهداف تصنيف المباني الخضراء.'],
            ['mixed-use', 'Mixed-Use Development', 'مشروع متعدد الاستخدامات', 'Commercial', 'Dubai', 'دبي', 2023, '#be123c', false,
                'Retail podium with offices above: district-cooling interface, smart metering and BMS integration.',
                'منصة تجارية تعلوها مكاتب: ربط مع التبريد المركزي، وعدادات ذكية، وتكامل مع نظام إدارة المبنى.'],
            ['data-centre', 'Tier III Data Centre', 'مركز بيانات Tier III', 'Industrial', 'Riyadh', 'الرياض', 2022, '#334155', false,
                'Concurrent-maintainable power and cooling, with full testing & commissioning witnessed by our team.',
                'طاقة وتبريد قابلان للصيانة دون توقف، مع حضور فريقنا لكامل اختبارات التشغيل.'],
        ];
        foreach ($rows as $i => [$slug, $ten, $tar, $cat, $len, $lar, $year, $accent, $feat, $sen, $sar]) {
            Project::create(['slug' => $slug, 'title_en' => $ten, 'title_ar' => $tar, 'category' => $cat, 'location_en' => $len, 'location_ar' => $lar,
                'year' => $year, 'accent' => $accent, 'featured' => $feat, 'summary_en' => $sen, 'summary_ar' => $sar, 'body_en' => $sen, 'body_ar' => $sar, 'sort' => $i]);
        }
    }
}
