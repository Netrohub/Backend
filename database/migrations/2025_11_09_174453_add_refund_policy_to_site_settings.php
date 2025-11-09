<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('site_settings')->insert([
            'key' => 'refund_policy',
            'value_ar' => $this->getDefaultRefundPolicyAr(),
            'value_en' => $this->getDefaultRefundPolicyEn(),
            'type' => 'html',
            'description' => 'Refund & Return Policy page content',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('site_settings')->where('key', 'refund_policy')->delete();
    }

    private function getDefaultRefundPolicyAr(): string
    {
        return <<<'HTML'
<h1>سياسة الاسترداد والإرجاع</h1>

<h2>نظرة عامة</h2>
<p>في منصة NXOLand، نلتزم بتوفير تجربة تداول آمنة وموثوقة. هذه السياسة توضح حقوقك والتزاماتك فيما يتعلق باسترداد الأموال وإرجاع الحسابات الرقمية المشتراة.</p>

<h2>نظام الضمان (Escrow)</h2>
<p><strong>فترة الضمان: 12 ساعة من وقت استلام بيانات الحساب</strong></p>
<p>عند شراء حساب رقمي على المنصة، يدخل المبلغ في نظام ضمان لمدة 12 ساعة. خلال هذه الفترة:</p>
<ul>
<li>يمكنك مراجعة الحساب والتأكد من مطابقته للوصف المعلن.</li>
<li>في حال وجود مشكلة، يمكنك فتح نزاع قبل انتهاء فترة الضمان.</li>
<li>بعد انقضاء 12 ساعة، يتم تحويل المبلغ للبائع تلقائيًا.</li>
</ul>

<h2>شروط الاسترداد الكامل</h2>
<p>يحق لك استرداد كامل المبلغ المدفوع في الحالات التالية:</p>
<ol>
<li><strong>الحساب غير مطابق للوصف:</strong>
   <ul>
   <li>مستوى اللعبة أو المحتوى مختلف عما تم الإعلان عنه</li>
   <li>نقص في العناصر أو الميزات المذكورة في الإعلان</li>
   <li>معلومات خاطئة أو مضللة في الوصف</li>
   </ul>
</li>
<li><strong>بيانات دخول خاطئة:</strong>
   <ul>
   <li>اسم المستخدم أو كلمة المرور غير صحيحة</li>
   <li>البريد الإلكتروني المرتبط غير صحيح أو لا يمكن الوصول إليه</li>
   </ul>
</li>
<li><strong>الحساب محظور أو مقيد:</strong>
   <ul>
   <li>الحساب محظور من المنصة (مثل PUBG Mobile، TikTok، إلخ)</li>
   <li>وجود عقوبات سابقة لم يتم الإفصاح عنها</li>
   <li>قيود على الحساب تمنع الاستخدام الكامل</li>
   </ul>
</li>
<li><strong>البائع يسترجع الحساب:</strong>
   <ul>
   <li>البائع يغير كلمة المرور بعد البيع</li>
   <li>البائع يسترجع الحساب عن طريق البريد الإلكتروني أو رقم الهاتف</li>
   </ul>
</li>
</ol>

<h2>شروط الاسترداد الجزئي</h2>
<p>في بعض الحالات، قد يتم استرداد جزئي للمبلغ:</p>
<ul>
<li>اختلافات طفيفة في الوصف لا تؤثر على جوهر المنتج</li>
<li>تأخير في تسليم بيانات الحساب (أكثر من 24 ساعة)</li>
<li>مشاكل فنية بسيطة يمكن حلها</li>
</ul>

<h2>الحالات التي لا يحق فيها الاسترداد</h2>
<p>لا يمكن استرداد المبلغ في الحالات التالية:</p>
<ol>
<li><strong>انتهاء فترة الضمان:</strong> بعد مرور 12 ساعة من استلام بيانات الحساب</li>
<li><strong>تأكيد الاستلام:</strong> إذا قمت بتأكيد استلام الحساب بنفسك</li>
<li><strong>سوء الاستخدام:</strong>
   <ul>
   <li>إذا قمت بتغيير بيانات الحساب (بريد، رقم هاتف، كلمة المرور)</li>
   <li>استخدام الحساب بشكل مخالف يؤدي لحظره</li>
   <li>مشاركة بيانات الحساب مع أطراف ثالثة</li>
   </ul>
</li>
<li><strong>أسباب شخصية:</strong>
   <ul>
   <li>تغيير رأيك بعد الشراء</li>
   <li>عدم الرضا عن اللعبة نفسها (ليس الحساب)</li>
   <li>شراء حساب آخر أفضل</li>
   </ul>
</li>
<li><strong>التواصل خارج المنصة:</strong> أي تعامل خارج نظام الضمان يفقدك الحماية</li>
</ol>

<h2>كيفية طلب الاسترداد (عن طريق النزاع)</h2>
<p><strong>⚠️ مهم: جميع طلبات الاسترداد تتم عبر نظام النزاعات فقط</strong></p>
<p>لا يوجد زر "طلب استرداد" مباشر. لطلب استرداد المبلغ، يجب فتح نزاع:</p>
<ol>
<li><strong>افتح نزاع</strong> من صفحة الطلب الخاص بك خلال 12 ساعة</li>
<li>اختر السبب المناسب من القائمة (حساب لا يطابق الوصف، بيانات خاطئة، إلخ)</li>
<li>قدم وصفًا تفصيليًا للمشكلة</li>
<li>أرفق أدلة واضحة (لقطات شاشة، فيديو، سجلات، إلخ)</li>
<li>انتظر مراجعة فريق الدعم للنزاع (24-48 ساعة)</li>
<li>سيقرر الفريق الحل: استرداد كامل، جزئي، أو رفض بناءً على الأدلة</li>
</ol>
<p><strong>بعد انتهاء 12 ساعة:</strong> لا يمكن فتح نزاع ويصبح البيع نهائياً.</p>

<h2>مدة معالجة الاسترداد</h2>
<ul>
<li><strong>قرار النزاع:</strong> 24-48 ساعة من فتح النزاع</li>
<li><strong>استرداد المبلغ:</strong> 1-4 أيام عمل بعد الموافقة</li>
<li><strong>الإشعارات:</strong> ستتلقى إشعارات في كل مرحلة عبر المنصة</li>
</ul>

<h2>شراء الخدمات والباقات</h2>
<p><strong>جميع مشتريات الخدمات الإضافية نهائية وغير قابلة للاسترداد:</strong></p>
<ul>
<li>تمييز الإعلانات (Featured Listings)</li>
<li>الباقات المدفوعة (Premium Plans)</li>
<li>الترويج للحسابات</li>
<li>الإعلانات المميزة</li>
</ul>
<p>هذه الخدمات يتم تفعيلها فورًا ولا يمكن إلغاؤها أو استردادها.</p>

<h2>حماية حقوقك</h2>
<p>نظام الضمان والنزاعات يضمن:</p>
<ul>
<li>حماية كاملة للمشتري خلال فترة الضمان (12 ساعة)</li>
<li>إمكانية فتح نزاع بسهولة من صفحة الطلب</li>
<li>فريق دعم متخصص لمراجعة كل نزاع بحيادية وعدالة</li>
<li>تقييم شامل للأدلة المقدمة من الطرفين</li>
<li>قرارات سريعة ومنصفة (24-48 ساعة)</li>
<li>استرداد فوري للمبلغ في حال ثبوت المخالفة</li>
</ul>
<p><strong>آلية عمل النزاع:</strong></p>
<ol>
<li>المشتري يفتح نزاع مع الأدلة</li>
<li>الإدارة تراجع الطلب والأدلة</li>
<li>يتم التواصل مع الطرفين إن لزم الأمر</li>
<li>قرار نهائي: استرداد كامل، جزئي، أو إغلاق النزاع</li>
<li>تنفيذ القرار فوراً</li>
</ol>

<h2>التواصل</h2>
<p>لأي استفسارات حول سياسة الاسترداد:</p>
<ul>
<li>قم بزيارة قسم <strong>المساعدة</strong> على المنصة</li>
<li>تواصل معنا عبر Discord الرسمي</li>
<li>قم بفتح تذكرة دعم من خلال المنصة</li>
</ul>

<h2>تعديل السياسة</h2>
<p>تحتفظ منصة NXOLand بالحق في تعديل هذه السياسة في أي وقت. سيتم إعلامك بأي تغييرات جوهرية عبر المنصة أو Discord.</p>

<p><strong>آخر تحديث:</strong> نوفمبر 2025</p>
HTML;
    }

    private function getDefaultRefundPolicyEn(): string
    {
        return <<<'HTML'
<h1>Refund & Return Policy</h1>

<h2>Overview</h2>
<p>At NXOLand, we are committed to providing a secure and trustworthy trading experience. This policy outlines your rights and responsibilities regarding refunds and returns for purchased digital accounts.</p>

<h2>Escrow System</h2>
<p><strong>Escrow Period: 12 hours from receiving account credentials</strong></p>
<p>When you purchase a digital account, the payment enters our escrow system for 12 hours. During this period:</p>
<ul>
<li>You can review the account and verify it matches the listing description.</li>
<li>If there's an issue, you can open a dispute before the escrow period ends.</li>
<li>After 12 hours, funds are automatically released to the seller.</li>
</ul>

<h2>Full Refund Eligibility</h2>
<p>You are entitled to a full refund in the following cases:</p>
<ol>
<li><strong>Account Does Not Match Description:</strong>
   <ul>
   <li>Game level or content differs from advertised</li>
   <li>Missing items or features mentioned in listing</li>
   <li>False or misleading information in description</li>
   </ul>
</li>
<li><strong>Incorrect Login Credentials:</strong>
   <ul>
   <li>Username or password provided is incorrect</li>
   <li>Associated email is incorrect or inaccessible</li>
   </ul>
</li>
<li><strong>Account is Banned or Restricted:</strong>
   <ul>
   <li>Account is banned from platform (PUBG Mobile, TikTok, etc.)</li>
   <li>Undisclosed previous violations or penalties</li>
   <li>Restrictions that prevent full account usage</li>
   </ul>
</li>
<li><strong>Seller Reclaims Account:</strong>
   <ul>
   <li>Seller changes password after sale</li>
   <li>Seller recovers account via email or phone</li>
   </ul>
</li>
</ol>

<h2>Partial Refund Eligibility</h2>
<p>In some cases, a partial refund may be issued:</p>
<ul>
<li>Minor discrepancies in description that don't affect core product</li>
<li>Delayed delivery of account credentials (more than 24 hours)</li>
<li>Minor technical issues that can be resolved</li>
</ul>

<h2>Non-Refundable Cases</h2>
<p>Refunds will NOT be issued in the following cases:</p>
<ol>
<li><strong>Escrow Period Expired:</strong> After 12 hours from receiving credentials</li>
<li><strong>Receipt Confirmed:</strong> If you manually confirmed account delivery</li>
<li><strong>Misuse:</strong>
   <ul>
   <li>You changed account details (email, phone, password)</li>
   <li>Account banned due to your violation of game rules</li>
   <li>You shared account credentials with third parties</li>
   </ul>
</li>
<li><strong>Personal Reasons:</strong>
   <ul>
   <li>Changed your mind after purchase</li>
   <li>Dissatisfaction with the game itself (not the account)</li>
   <li>Found a better account elsewhere</li>
   </ul>
</li>
<li><strong>Off-Platform Communication:</strong> Any deal outside escrow system voids protection</li>
</ol>

<h2>How to Request a Refund (Through Dispute System)</h2>
<p><strong>⚠️ Important: All refund requests are processed through our dispute system only</strong></p>
<p>There is no direct "Request Refund" button. To request a refund, you must open a dispute:</p>
<ol>
<li><strong>Open a Dispute</strong> from your order page within 12 hours</li>
<li>Select the appropriate reason (account doesn't match, wrong credentials, etc.)</li>
<li>Provide a detailed description of the issue</li>
<li>Attach clear evidence (screenshots, videos, logs, etc.)</li>
<li>Wait for support team to review the dispute (24-48 hours)</li>
<li>Team will decide resolution: full refund, partial refund, or rejection based on evidence</li>
</ol>
<p><strong>After 12 hours expire:</strong> Disputes cannot be opened and the sale is final.</p>

<h2>Refund Processing Time</h2>
<ul>
<li><strong>Dispute Resolution:</strong> 24-48 hours from dispute opening</li>
<li><strong>Refund Processing:</strong> 1-4 business days after approval</li>
<li><strong>Notifications:</strong> You'll receive updates at each stage via platform</li>
</ul>

<h2>Services & Packages Purchases</h2>
<p><strong>All service purchases are final and non-refundable:</strong></p>
<ul>
<li>Featured Listings</li>
<li>Premium Plans</li>
<li>Account Promotions</li>
<li>Highlighted Advertisements</li>
</ul>
<p>These services are activated immediately and cannot be canceled or refunded.</p>

<h2>Your Protection</h2>
<p>Our escrow and dispute system guarantees:</p>
<ul>
<li>Full buyer protection during 12-hour escrow period</li>
<li>Easy dispute opening directly from order page</li>
<li>Specialized support team for impartial dispute review</li>
<li>Comprehensive evaluation of evidence from both parties</li>
<li>Quick and fair decisions (24-48 hours)</li>
<li>Immediate refund processing if violation is proven</li>
</ul>
<p><strong>Dispute Process:</strong></p>
<ol>
<li>Buyer opens dispute with evidence</li>
<li>Admin reviews order and evidence</li>
<li>Communication with both parties if needed</li>
<li>Final decision: full refund, partial refund, or dispute closure</li>
<li>Decision executed immediately</li>
</ol>

<h2>Contact Us</h2>
<p>For any questions about our refund policy:</p>
<ul>
<li>Visit the <strong>Help Center</strong> on the platform</li>
<li>Contact us via official Discord server</li>
<li>Open a support ticket through the platform</li>
</ul>

<h2>Policy Updates</h2>
<p>NXOLand reserves the right to modify this policy at any time. You will be notified of any significant changes via the platform or Discord.</p>

<p><strong>Last Updated:</strong> November 2025</p>
HTML;
    }
};
