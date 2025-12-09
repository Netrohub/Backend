<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value_ar')->nullable();
            $table->text('value_en')->nullable();
            $table->string('type')->default('text'); // text, textarea, html, json
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Insert default values
        DB::table('site_settings')->insert([
            [
                'key' => 'terms_and_conditions',
                'value_ar' => $this->getDefaultTermsAr(),
                'value_en' => $this->getDefaultTermsEn(),
                'type' => 'html',
                'description' => 'Terms and Conditions page content',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'privacy_policy',
                'value_ar' => $this->getDefaultPrivacyAr(),
                'value_en' => $this->getDefaultPrivacyEn(),
                'type' => 'html',
                'description' => 'Privacy Policy page content',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }

    private function getDefaultTermsAr(): string
    {
        return <<<'HTML'
<h1>الشروط والأحكام</h1>

<h2>نبذة عن المنصة</h2>
<p>منصة نيكسولاند (Nxoland) هي مشروع تابع لشركة [اسم الشركة/الكيان القانوني المسجل]، والمسجلة رسميًا لدى وزارة التجارة في المملكة العربية السعودية.</p>
<p>تُقدّم المنصة سوقًا رقميًا آمنًا لبيع وشراء حسابات الألعاب الرقمية والخدمات الافتراضية بشكل موثوق وشفاف، مع نظام وساطة متكامل يضمن حماية حقوق جميع الأطراف.</p>
<p>توفر المنصة طرق دفع متعددة عبر شركاء معتمدين، وتُخصم نسبة خدمة تتراوح بين 3% و10% حسب نوع الحساب أو الخدمة المختارة وخطة المستخدم.</p>

<h2>الشروط العامة</h2>

<h3>عرض الحسابات والخدمات</h3>
<ul>
<li>يُمنع عرض أي حساب مملوك لطرف ثالث أو لم يَثبت امتلاكه قانونيًا.</li>
<li>لا يجوز عرض نفس الحساب من أكثر من عضو في الوقت نفسه.</li>
</ul>

<h3>إتمام عمليات البيع</h3>
<ul>
<li>بعد إتمام عملية البيع، يُمنع سحب الحساب أو استرجاعه بأي شكل.</li>
<li>أي محاولة سحب أو استرجاع بعد البيع تمنح المنصة الحق في حظر الحساب واتخاذ الإجراءات القانونية المناسبة.</li>
</ul>

<h3>دور المنصة</h3>
<p>تعمل المنصة كوسيط موثوق بين البائع والمشتري، ولا تتحمل أي مسؤولية عن التعاملات أو النزاعات التي تتم خارج المنصة.</p>

<h2>تحويل الرصيد</h2>
<ul>
<li>يتم تحويل الرصيد بعد مرور 12 ساعة من إتمام الطلب دون وجود نزاع.</li>
<li>مدة السحب تتراوح بين 1 إلى 4 أيام عمل.</li>
<li>لا تتحمل المنصة مسؤولية الأخطاء في بيانات التحويل المقدمة من المستخدم.</li>
</ul>

<h2>النزاعات</h2>
<p>يحق للمستخدم فتح نزاع خلال 12 ساعة فقط من تنفيذ الطلب.</p>
<p>بعد هذه المدة، تُعتبر العملية نهائية والمنصة غير مسؤولة عن أي مطالبات لاحقة.</p>

<h2>سياسة الاسترجاع</h2>
<p>جميع المبيعات داخل متجر المنصة (مثل الباقات أو الإعلانات أو الخدمات الإضافية) نهائية وغير قابلة للاسترجاع أو التحويل.</p>

<h2>استخدام الحساب</h2>
<ul>
<li>لا يُسمح بامتلاك أكثر من حساب واحد.</li>
<li>مشاركة الحساب أو بيعه أو تحويله لشخص آخر ممنوع نهائيًا.</li>
<li>يُمنع نشر أي وسيلة تواصل داخل الوصف أو الصور.</li>
</ul>

<h2>حظر الحسابات</h2>
<p>تحتفظ المنصة بالحق في حظر أي حساب أو مصادرة الأرصدة في حال الاشتباه بأي نشاط احتيالي أو مخالف للقوانين المحلية والدولية.</p>

<h2>توثيق الحساب</h2>
<p>يمكن طلب التوثيق عند بلوغ المستخدم حد سحب 400 دولار أمريكي، ويجوز للمنصة سحب التوثيق أو رفض الطلب وفقًا لتقديرها.</p>

<h2>تعديل الشروط</h2>
<p>يحق للمنصة تعديل هذه الشروط والأحكام في أي وقت دون إشعار مسبق.</p>
<p>استمرارك في استخدام المنصة بعد التحديث يعني موافقتك الضمنية على الشروط الجديدة.</p>

<hr>

<h2>ملخص بالعربية</h2>
<p>باستخدامك منصة نيكسولاند (Nxoland)، فأنت توافق على جميع البنود التالية:</p>
<ul>
<li>المنصة وسيط رقمي آمن لبيع وشراء حسابات الألعاب والخدمات الافتراضية.</li>
<li>لا يُسمح بعرض أو بيع أي حساب غير مملوك لك.</li>
<li>يُمنع التعامل أو الدفع خارج المنصة.</li>
<li>تُعتبر جميع المبيعات نهائية.</li>
<li>للمنصة كامل الصلاحية في حظر أو مصادرة الحسابات المخالفة أو المشبوهة.</li>
<li>استمرارك في استخدام المنصة بعد أي تعديل يعني قبولك بالشروط الجديدة.</li>
</ul>
HTML;
    }

    private function getDefaultTermsEn(): string
    {
        return <<<'HTML'
<h1>Terms & Conditions</h1>

<h2>About the Platform</h2>
<p>Nxoland is a digital marketplace owned and operated by [Your Registered Company Name], officially registered under the Saudi Ministry of Commerce.</p>
<p>The platform provides a secure and transparent environment for buying and selling gaming accounts and digital services, with a trusted escrow system to ensure fair transactions.</p>
<p>Payment options are supported through verified partners, with a service fee ranging from 3% to 10%, depending on the product type and user plan.</p>

<h2>General Terms</h2>

<h3>Account Listings</h3>
<ul>
<li>Users may only list accounts they fully own.</li>
<li>Duplicate listings of the same account are prohibited.</li>
</ul>

<h3>After-Sale Policy</h3>
<ul>
<li>Once a sale is completed, the account cannot be withdrawn or reclaimed.</li>
<li>Any attempt to retrieve the sold account may result in account suspension and legal action.</li>
</ul>

<h3>Platform Role</h3>
<p>Nxoland acts as a trusted intermediary only and is not responsible for any off-platform transactions or disputes.</p>

<h2>Balance & Withdrawals</h2>
<ul>
<li>Funds are released 12 hours after order completion if no dispute is opened.</li>
<li>Withdrawals are processed within 1–4 business days.</li>
<li>Users are responsible for ensuring the accuracy of their payout information.</li>
</ul>

<h2>Disputes</h2>
<p>Buyers can open a dispute within 12 hours of purchase. After this period, the transaction is considered final.</p>

<h2>Refund Policy</h2>
<p>All purchases (plans, promotions, and add-ons) are non-refundable and non-transferable.</p>

<h2>Account Use</h2>
<ul>
<li>Only one account per user is allowed.</li>
<li>Sharing or transferring accounts is strictly prohibited.</li>
<li>Contact information may not be included in product descriptions.</li>
</ul>

<h2>Account Suspension</h2>
<p>Nxoland reserves the right to suspend or terminate any account engaged in fraudulent or suspicious activities, with or without notice.</p>

<h2>Verification</h2>
<p>Verification may be required for users reaching a withdrawal limit of $400 USD. Nxoland may revoke or deny verification at its sole discretion.</p>

<h2>Updates to Terms</h2>
<p>Nxoland may modify these terms at any time. Continued use of the platform constitutes acceptance of the updated terms.</p>
HTML;
    }

    private function getDefaultPrivacyAr(): string
    {
        return <<<'HTML'
<h1>سياسة الخصوصية</h1>

<h2>مقدمة</h2>
<p>في منصة NXOLand، نلتزم بحماية خصوصية مستخدمينا وضمان أمان بياناتهم الشخصية. نحرص على تطبيق أعلى معايير الحماية وفقًا لنظام حماية البيانات الشخصية في المملكة العربية السعودية، ونضمن أن معلوماتك تُستخدم فقط لتقديم خدمات آمنة وموثوقة داخل المنصة.</p>

<h2>المعلومات التي نجمعها</h2>
<p>نقوم بجمع أنواع مختلفة من البيانات لضمان تقديم تجربة آمنة ومتكاملة تشمل:</p>
<ul>
<li><strong>المعلومات الشخصية:</strong> الاسم، رقم الهوية أو الإقامة، البريد الإلكتروني، رقم الهاتف.</li>
<li><strong>معلومات الحساب:</strong> اسم المستخدم، كلمة المرور، وسجل النشاط داخل المنصة.</li>
<li><strong>بيانات المتجر (للبائعين):</strong> رقم السجل التجاري، معلومات الحساب البنكي، ومستندات التحقق (KYC).</li>
<li><strong>بيانات الدفع:</strong> تفاصيل العمليات المالية عبر بوابة Tap لضمان أمان وسلامة التحويلات.</li>
<li><strong>معلومات التواصل:</strong> مثل البريد الإلكتروني أو حساب Discord عند التواصل مع الدعم الفني.</li>
</ul>

<h2>الغرض من جمع البيانات</h2>
<p>نستخدم بياناتك للأغراض التالية:</p>
<ul>
<li>تسهيل عمليات البيع والشراء وضمان حقوق جميع الأطراف.</li>
<li>التحقق من هوية المستخدمين وحماية الحسابات من الاستخدام غير المشروع.</li>
<li>تحسين أداء المنصة وتجربة المستخدم.</li>
<li>تنفيذ عمليات الدفع الآمنة من خلال Tap.</li>
<li>التواصل مع المستخدمين عند الحاجة عبر Discord أو البريد الإلكتروني.</li>
</ul>

<h2>حماية وأمان البيانات</h2>
<p>نلتزم بتطبيق أعلى معايير الأمان، بما في ذلك:</p>
<ul>
<li>تشفير البيانات أثناء النقل والتخزين.</li>
<li>ضوابط وصول مشددة للموظفين المخولين فقط.</li>
<li>مراجعات أمنية دورية لأنظمة المنصة.</li>
</ul>
<p>مع ذلك، لا يمكن ضمان أمان البيانات بنسبة 100% عبر الإنترنت، لذا نوصي بالحفاظ على سرية بيانات الدخول الخاصة بك.</p>

<h2>مشاركة البيانات</h2>
<p>نحن لا نشارك بياناتك إلا في الحالات التالية:</p>
<ul>
<li>تنفيذ عمليات الدفع عبر Tap Company.</li>
<li>الامتثال لطلبات الجهات الحكومية أو القضائية.</li>
<li>التعاون مع شركاء الخدمة (مثل شركات الشحن أو التحقق).</li>
<li>في حال الاشتباه في نشاط غير قانوني لحماية المنصة والمستخدمين.</li>
</ul>

<h2>حقوق المستخدم</h2>
<p>يحق لك:</p>
<ul>
<li>طلب نسخة من بياناتك.</li>
<li>تعديل أو تصحيح بياناتك.</li>
<li>حذف بياناتك عند الرغبة أو في حال إغلاق الحساب.</li>
<li>سحب موافقتك على معالجة بياناتك في أي وقت.</li>
</ul>

<h2>التواصل معنا</h2>
<p>يتم التواصل الرسمي فقط عبر خادم Discord الرسمي لمنصة NXOLand، ولا يتم تقديم الدعم عبر أي قناة أخرى.</p>
<p>للاستفسارات، يرجى التواصل عبر قناة Support داخل Discord.</p>

<h2>التعديلات</h2>
<p>تحتفظ منصة NXOLand بالحق في تعديل سياسة الخصوصية عند الحاجة. سيتم إعلام المستخدمين بأي تحديثات رئيسية من خلال المنصة أو عبر Discord.</p>
HTML;
    }

    private function getDefaultPrivacyEn(): string
    {
        return <<<'HTML'
<h1>Privacy Policy</h1>

<h2>Introduction</h2>
<p>At NXOLand, we value your privacy and are committed to protecting your personal data. We comply with the Saudi Personal Data Protection Law (PDPL) and apply global best practices to ensure your information remains secure and confidential.</p>

<h2>Information We Collect</h2>
<p>We collect several types of data to provide a safe and complete marketplace experience, including:</p>
<ul>
<li><strong>Personal Information:</strong> Name, ID or residence number, email, and phone number.</li>
<li><strong>Account Details:</strong> Username, password, and activity logs.</li>
<li><strong>Store Information (for sellers):</strong> Commercial registration number, bank details, and KYC verification documents.</li>
<li><strong>Payment Information:</strong> Transactions processed securely via Tap Company.</li>
<li><strong>Contact Information:</strong> Email and Discord account for support communication.</li>
</ul>

<h2>Purpose of Data Collection</h2>
<p>We use your data to:</p>
<ul>
<li>Facilitate buying and selling operations securely.</li>
<li>Verify user identities and prevent fraud.</li>
<li>Improve user experience and system performance.</li>
<li>Process payments safely through Tap.</li>
<li>Communicate with users through Discord or email.</li>
</ul>

<h2>Data Security</h2>
<p>We implement top-tier security measures, including:</p>
<ul>
<li>Encryption of data in transit and storage.</li>
<li>Strict access controls for authorized personnel.</li>
<li>Regular system audits for compliance and safety.</li>
</ul>
<p>However, no online system is 100% secure, so we advise users to keep their login credentials confidential.</p>

<h2>Data Sharing</h2>
<p>We only share data when necessary:</p>
<ul>
<li>With Tap Company for payment processing.</li>
<li>When required by law or government authorities.</li>
<li>With service partners (e.g., shipping or identity verification).</li>
<li>In case of suspicious or fraudulent activity.</li>
</ul>

<h2>User Rights</h2>
<p>You have the right to:</p>
<ul>
<li>Access your stored data.</li>
<li>Correct or update your personal information.</li>
<li>Request deletion of your data or account.</li>
<li>Withdraw consent for data processing at any time.</li>
</ul>

<h2>Contact Us</h2>
<p>Official communication is exclusively through our NXOLand Discord Server.</p>
<p>For inquiries, please contact our Support channel on Discord.</p>

<h2>Updates</h2>
<p>NXOLand reserves the right to update this privacy policy when necessary. Users will be notified of significant changes via the website or Discord.</p>
HTML;
    }
};

