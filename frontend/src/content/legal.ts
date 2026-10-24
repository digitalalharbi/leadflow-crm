/**
 * The public pages' text, in both languages.
 *
 * Written to say only what is true of the product today, and to leave a visible marker (a token such as
 * `{company}`) wherever a fact is not confirmed yet: the legal name, the contact mailbox, the postal
 * address. The tokens are rendered by the page as a plain "to be added before launch" marker until the
 * deployment supplies the value (see `publicInfo.ts`). Nothing here is a certification claim, a legal
 * opinion, or a promise the product does not keep.
 */
export interface Section {
  id: string
  heading: string
  paragraphs?: string[]
  bullets?: string[]
}

export interface Doc {
  title: string
  summary: string
  sections: Section[]
}

type Bilingual = { ar: Doc; en: Doc }

const privacy: Bilingual = {
  ar: {
    title: 'سياسة الخصوصية',
    summary: 'كيف تتعامل «مهتم» مع البيانات: ما الذي تجمعه ولماذا، ومن يصل إليه، وكيف تطلب حذفه.',
    sections: [
      { id: 'who', heading: 'من نحن', paragraphs: ['«مهتم» منصة لإدارة العملاء المحتملين والتأهيل والمتابعة والحملات والتقارير، تقدّمها {company}.', 'تنطبق هذه السياسة على موقع «مهتم» وتطبيقها. وتصف التعامل مع البيانات في الحالتين: حساب من يستخدم المنصة، وبيانات العملاء المحتملين التي تُدار داخل مساحة عمل.'] },
      { id: 'collect', heading: 'البيانات التي نعالجها', bullets: ['بيانات الحساب: الاسم والبريد الإلكتروني ورقم الجوال والدور والصلاحيات وتفضيلات الإشعارات.', 'بيانات العملاء المحتملين: ما تدخله مساحة العمل أو يصل إليها من إعلاناتها ونماذجها وواتساب، مثل الاسم والهاتف والبريد والمدينة والمشروع المهتم به والمحادثات والملاحظات والمواعيد.', 'بيانات الحملات: الحملات والإنفاق والنتائج التي تجلبها المنصات الإعلانية المربوطة أو تُدخَل يدويًا.', 'بيانات التتبع: نقرات روابط التتبع ورموز QR وبيانات المصدر (UTM) التي تنشئها مساحة العمل لحملاتها.', 'بيانات تقنية محدودة: ما يلزم لتشغيل الخدمة وحمايتها، مثل سجلات الدخول والأخطاء وعناوين الطلبات.'] },
      { id: 'roles', heading: 'من يقرر ماذا', paragraphs: ['مساحة العمل هي التي تقرر ما تجمعه عن عملائها المحتملين وكيف تستخدمه. ونحن نخزّن هذه البيانات ونعالجها لتشغيل الخدمة لصالحها، ولا نبيعها ولا نستخدمها في إعلانات لا تخص مساحة العمل نفسها.', 'إن كنت عميلًا محتملًا وتريد معرفة بياناتك أو حذفها، فالجهة المعنية أولًا هي الشركة التي تواصلت معك. ويمكنك أيضًا مراسلتنا عبر صفحة حذف البيانات.'] },
      { id: 'cookies', heading: 'ملفات الارتباط والتخزين المحلي', paragraphs: ['واجهة «مهتم» لا تحمّل أدوات تحليلات أو إعلانات من أطراف ثالثة، ولا تستخدم خطوطًا من خدمات خارجية.', 'تحتفظ الواجهة في متصفحك بما يلزم لعملها فقط: رمز الجلسة بعد تسجيل الدخول، واللغة والمظهر الذي اخترتهما، وتذكّر إغلاقك لتنبيهات معيّنة. تُمسح عند تسجيل الخروج أو بمسح بيانات المتصفح.', 'روابط التتبع التي تنشئها مساحة العمل لحملاتها قد تسجّل نقرات الزوار لأغراض الإحصاء، وهي من إعداد مساحة العمل لا من الواجهة.'] },
      { id: 'integrations', heading: 'التكاملات', paragraphs: ['عندما تربط مساحة العمل منصة مثل Meta أو TikTok أو Snapchat أو Google Ads أو LinkedIn أو X أو واتساب للأعمال أو Google Sheets أو مزوّد بريد أو اتصالات، تنتقل البيانات بين «مهتم» وتلك المنصة بحسب الصلاحيات التي منحتها مساحة العمل. بيانات الاعتماد الخاصة بالتكاملات تُحفظ مشفّرة ولا تُعرض مرة أخرى.', 'كل منصة تخضع لشروطها وسياسة خصوصيتها. ويمكن لمساحة العمل فصل أي تكامل في أي وقت.'] },
      { id: 'analytics', heading: 'التحليلات', paragraphs: ['الأرقام والتقارير التي يراها المستخدم تُحسب من بيانات مساحة عمله داخل المنصة. ولا نرسل بيانات المستخدمين إلى خدمات تحليلات خارجية من واجهة المنصة.'] },
      { id: 'communications', heading: 'الرسائل والاتصالات', paragraphs: ['قد نرسل رسائل تشغيلية إلى مستخدمي المنصة، مثل تنبيهات عميل ساخن أو ملخصات يومية وأسبوعية وشهرية، بحسب تفضيلات كل مستخدم، عبر البريد أو واتساب عند تفعيلهما.', 'الرسائل التي تُرسل إلى العملاء المحتملين تُرسلها مساحة العمل، وتحترم المنصة طلب التوقف عن الرسائل الذي يسجّله العميل.'] },
      { id: 'retention', heading: 'مدة الاحتفاظ', paragraphs: ['نحتفظ بالبيانات ما دامت مساحة العمل فعّالة. وتُحذف الحمولات الخام للاستيراد بعد مدة يحددها إعداد الخدمة. وعند إغلاق مساحة العمل أو عند طلب مؤكد، نحذف البيانات أو نُخفي هويتها وفق ما تسمح به الأنظمة.'] },
      { id: 'deletion', heading: 'الحذف', paragraphs: ['يمكنك طلب حذف بياناتك أو بيانات عميل محتمل. اتبع الخطوات في صفحة حذف البيانات.'] },
      { id: 'security', heading: 'الأمان', paragraphs: ['نطبّق ضوابط أمان موصوفة في صفحة الأمان، منها عزل مساحات العمل والصلاحيات وسجل التدقيق وتشفير بيانات الاعتماد.'] },
      { id: 'processors', heading: 'الأطراف الخارجية التي تعالج بيانات لحسابنا', paragraphs: ['نستعين بخدمات خارجية لتشغيل المنصة، قد تشمل الاستضافة وإرسال البريد وقنوات المراسلة والمنصات الإعلانية التي تربطها مساحة العمل، ومزوّد الذكاء الاصطناعي إن فعّلته مساحة العمل بمفتاحها. القائمة النهائية بأسماء المزوّدين: {processors}.'] },
      { id: 'rights', heading: 'حقوقك', paragraphs: ['يمكنك طلب الاطلاع على بياناتك أو تصحيحها أو حذفها، والاعتراض على معالجتها، بحسب ما تسمح به الأنظمة المعمول بها. نراجع كل طلب ونردّ عليه.'] },
      { id: 'contact', heading: 'التواصل', paragraphs: ['الجهة: {company}.', 'البريد: {email}.', 'العنوان: {address}.'] },
      { id: 'changes', heading: 'تحديث هذه السياسة', paragraphs: ['قد نحدّث هذه السياسة عند تغيّر الخدمة أو الأنظمة. نعرض تاريخ آخر مراجعة في أعلى الصفحة.'] },
    ],
  },
  en: {
    title: 'Privacy policy',
    summary: 'How مهتم handles data: what it processes and why, who can reach it, and how to ask for it to be deleted.',
    sections: [
      { id: 'who', heading: 'Who we are', paragraphs: ['مهتم is a platform for managing leads, qualification, follow-up, campaigns and reports, provided by {company}.', 'This policy covers the مهتم website and app, and describes how data is handled in two cases: the account of the person using the platform, and the lead data managed inside a workspace.'] },
      { id: 'collect', heading: 'The data we process', bullets: ['Account data: name, email, mobile number, role, permissions and notification preferences.', 'Lead data: what a workspace enters or receives from its ads, forms and WhatsApp — name, phone, email, city, project of interest, conversations, notes and appointments.', 'Campaign data: campaigns, spend and results pulled from connected ad platforms or entered by hand.', 'Tracking data: clicks on tracking links, QR codes and source (UTM) data created by a workspace for its campaigns.', 'Limited technical data needed to run and protect the service, such as sign-in and error logs and request addresses.'] },
      { id: 'roles', heading: 'Who decides what', paragraphs: ['A workspace decides what it collects about its leads and how it uses it. We store and process that data to run the service on its behalf; we do not sell it and do not use it for advertising that is not the workspace’s own.', 'If you are a lead and want to know or delete your data, the company that contacted you is the first place to ask. You can also write to us through the data deletion page.'] },
      { id: 'cookies', heading: 'Cookies and local storage', paragraphs: ['The مهتم interface loads no third-party analytics or advertising tools, and uses no fonts from external services.', 'It keeps only what it needs in your browser: the session token after you sign in, the language and appearance you chose, and your dismissal of certain notices. These are cleared when you sign out or clear browser data.', 'Tracking links a workspace creates for its campaigns may record visitors’ clicks for statistics; those are set up by the workspace, not by the interface.'] },
      { id: 'integrations', heading: 'Integrations', paragraphs: ['When a workspace connects a platform such as Meta, TikTok, Snapchat, Google Ads, LinkedIn, X, WhatsApp Business, Google Sheets, or an email or telephony provider, data flows between مهتم and that platform according to the permissions the workspace granted. Integration credentials are stored encrypted and are never shown again.', 'Each platform is governed by its own terms and privacy policy. A workspace can disconnect any integration at any time.'] },
      { id: 'analytics', heading: 'Analytics', paragraphs: ['The figures and reports a user sees are computed from their workspace’s data inside the platform. We do not send users’ data to external analytics services from the interface.'] },
      { id: 'communications', heading: 'Messages and communications', paragraphs: ['We may send operational messages to platform users — hot-lead alerts, daily, weekly and monthly summaries — by email or WhatsApp where enabled, according to each user’s preferences.', 'Messages to leads are sent by the workspace, and the platform honours a lead’s request to stop receiving them.'] },
      { id: 'retention', heading: 'Retention', paragraphs: ['We keep data while a workspace is active. Raw import payloads are deleted after a period set by the service configuration. When a workspace is closed, or on a confirmed request, we delete the data or anonymise it as the applicable rules allow.'] },
      { id: 'deletion', heading: 'Deletion', paragraphs: ['You can ask for your data, or a lead’s data, to be deleted. Follow the steps on the data deletion page.'] },
      { id: 'security', heading: 'Security', paragraphs: ['We apply the controls described on the security page, including workspace isolation, permissions, an audit log and encrypted credentials.'] },
      { id: 'processors', heading: 'Third parties that process data for us', paragraphs: ['We use outside services to run the platform, which may include hosting, email delivery, messaging channels, the ad platforms a workspace connects, and an AI provider if a workspace enables one with its own key. The final list of providers: {processors}.'] },
      { id: 'rights', heading: 'Your rights', paragraphs: ['You can ask to see, correct or delete your data, and to object to its processing, as the applicable rules allow. We review and answer each request.'] },
      { id: 'contact', heading: 'Contact', paragraphs: ['Provider: {company}.', 'Email: {email}.', 'Address: {address}.'] },
      { id: 'changes', heading: 'Changes to this policy', paragraphs: ['We may update this policy when the service or the rules change. The date of the last review is shown at the top of the page.'] },
    ],
  },
}

const terms: Bilingual = {
  ar: {
    title: 'الشروط والأحكام',
    summary: 'القواعد التي تحكم استخدام «مهتم»: الحسابات والمسؤوليات والتكاملات والتوفر وملكية البيانات.',
    sections: [
      { id: 'service', heading: 'استخدام الخدمة', paragraphs: ['«مهتم» خدمة برمجية لإدارة العملاء المحتملين والتأهيل والمتابعة والحملات والتقارير، تقدّمها {company}. باستخدامك الخدمة توافق على هذه الشروط. إن كنت تستخدمها نيابة عن شركة، فأنت تؤكد أن لك صلاحية إلزامها.'] },
      { id: 'accounts', heading: 'الحسابات', bullets: ['تُنشأ مساحات العمل والحسابات عبر فريقنا أو مسؤول مساحة العمل.', 'أنت مسؤول عن سرية بيانات دخولك وعن كل نشاط يجري بحسابك.', 'يحدد مسؤول مساحة العمل أدوار المستخدمين وصلاحياتهم ومن يرى ماذا.', 'أبلغنا فورًا إن اشتبهت في دخول غير مصرّح به.'] },
      { id: 'responsibilities', heading: 'المسؤوليات', paragraphs: ['مساحة العمل مسؤولة عن مشروعية جمع بيانات عملائها المحتملين واستخدامها، وعن الحصول على الموافقات اللازمة للتواصل معهم، وعن محتوى رسائلها وحملاتها.', 'نحن مسؤولون عن تشغيل الخدمة وفق وصفها وحماية بيانات مساحات العمل بالضوابط المذكورة في صفحة الأمان.'] },
      { id: 'integrations', heading: 'التكاملات وواجهات الأطراف الثالثة', paragraphs: ['تعتمد بعض الميزات على منصات خارجية مثل Meta وTikTok وSnapchat وGoogle وLinkedIn وX وواتساب. هذه المنصات تغيّر واجهاتها وشروطها ووصولها بقراراتها، وقد يتأثر عمل التكامل بذلك دون أن يكون عطلًا في «مهتم». استخدامك لها خاضع لشروطها.'] },
      { id: 'availability', heading: 'التوفر', paragraphs: ['نعمل على إتاحة الخدمة باستمرار، ولا نضمن خلوها من الانقطاع أو الأخطاء. قد نوقفها مؤقتًا للصيانة أو التحديث، ونحاول إعلام المستخدمين مسبقًا حين يكون ذلك ممكنًا.'] },
      { id: 'data', heading: 'ملكية البيانات', paragraphs: ['البيانات التي تدخلها مساحة العمل أو تجلبها عبر تكاملاتها تبقى لها. وتمنحنا حق معالجتها بالقدر اللازم لتشغيل الخدمة. تستطيع مساحة العمل تصدير بياناتها وطلب حذفها وفق صفحة حذف البيانات.'] },
      { id: 'acceptable', heading: 'الاستخدام المقبول', bullets: ['لا تستخدم الخدمة لأي نشاط مخالف للأنظمة.', 'لا ترسل رسائل غير مرغوب فيها ولا تتواصل مع من طلب التوقف.', 'لا تحاول الوصول إلى بيانات مساحة عمل أخرى أو تعطيل الخدمة أو تجاوز حدودها.', 'لا تشارك بيانات دخولك ولا تستخدم الخدمة لإخفاء جهة أخرى.'] },
      { id: 'suspension', heading: 'الإيقاف', paragraphs: ['قد نوقف حسابًا أو مساحة عمل عند مخالفة هذه الشروط أو لحماية الخدمة أو المستخدمين، أو عند انتهاء الاشتراك. نبلغ الجهة المعنية بالسبب حين يكون ذلك ممكنًا.'] },
      { id: 'limitation', heading: 'حدود المسؤولية', paragraphs: ['تُقدَّم الخدمة كما هي ضمن حدود ما تسمح به الأنظمة. لا نتحمل الخسائر غير المباشرة أو فوات الربح الناتجة عن استخدام الخدمة أو عدم القدرة على استخدامها، ولا نتحمل ما ينتج عن قرارات المنصات الخارجية أو قيودها.'] },
      { id: 'termination', heading: 'الإنهاء', paragraphs: ['يمكنك التوقف عن استخدام الخدمة في أي وقت وطلب إغلاق مساحة العمل. وعند الإنهاء نتعامل مع البيانات وفق سياسة الخصوصية وصفحة حذف البيانات.'] },
      { id: 'updates', heading: 'تحديث الشروط', paragraphs: ['قد نعدّل هذه الشروط. نعرض تاريخ آخر مراجعة في أعلى الصفحة، واستمرارك في الاستخدام بعد التعديل يعني قبوله.'] },
      { id: 'contact', heading: 'التواصل', paragraphs: ['الجهة: {company}.', 'البريد: {email}.', 'العنوان: {address}.'] },
    ],
  },
  en: {
    title: 'Terms and conditions',
    summary: 'The rules that govern the use of مهتم: accounts, responsibilities, integrations, availability and data ownership.',
    sections: [
      { id: 'service', heading: 'Using the service', paragraphs: ['مهتم is a software service for managing leads, qualification, follow-up, campaigns and reports, provided by {company}. By using it you agree to these terms. If you use it for a company, you confirm you may bind it.'] },
      { id: 'accounts', heading: 'Accounts', bullets: ['Workspaces and accounts are created by our team or by a workspace administrator.', 'You are responsible for keeping your sign-in details secret and for everything done with your account.', 'A workspace administrator sets users’ roles, permissions and who sees what.', 'Tell us at once if you suspect unauthorised access.'] },
      { id: 'responsibilities', heading: 'Responsibilities', paragraphs: ['A workspace is responsible for the lawfulness of collecting and using its leads’ data, for obtaining the consents needed to contact them, and for the content of its messages and campaigns.', 'We are responsible for running the service as described and for protecting workspaces’ data with the controls on the security page.'] },
      { id: 'integrations', heading: 'Integrations and third-party APIs', paragraphs: ['Some features depend on outside platforms such as Meta, TikTok, Snapchat, Google, LinkedIn, X and WhatsApp. Those platforms change their interfaces, terms and access at their own discretion, and an integration may be affected without a fault in مهتم. Your use of them is subject to their terms.'] },
      { id: 'availability', heading: 'Availability', paragraphs: ['We work to keep the service available but do not guarantee it will be free of interruption or error. We may pause it for maintenance or updates and try to give users notice where we can.'] },
      { id: 'data', heading: 'Data ownership', paragraphs: ['The data a workspace enters or pulls through its integrations stays the workspace’s. It grants us the right to process it as far as needed to run the service. A workspace may export its data and ask for it to be deleted as the data deletion page describes.'] },
      { id: 'acceptable', heading: 'Acceptable use', bullets: ['Do not use the service for anything unlawful.', 'Do not send unwanted messages or contact someone who asked to stop.', 'Do not try to reach another workspace’s data, disrupt the service or get around its limits.', 'Do not share your sign-in details or use the service to hide another party.'] },
      { id: 'suspension', heading: 'Suspension', paragraphs: ['We may suspend an account or workspace for breach of these terms, to protect the service or its users, or when a subscription ends. We tell the party concerned why where we can.'] },
      { id: 'limitation', heading: 'Limitation of liability', paragraphs: ['The service is provided as it is, within what the applicable rules allow. We are not liable for indirect loss or lost profit arising from using or being unable to use the service, nor for the decisions or limits of outside platforms.'] },
      { id: 'termination', heading: 'Termination', paragraphs: ['You may stop using the service at any time and ask for the workspace to be closed. On termination we handle data as the privacy policy and the data deletion page describe.'] },
      { id: 'updates', heading: 'Updates to these terms', paragraphs: ['We may amend these terms. The date of the last review is shown at the top of the page; continuing to use the service after a change means accepting it.'] },
      { id: 'contact', heading: 'Contact', paragraphs: ['Provider: {company}.', 'Email: {email}.', 'Address: {address}.'] },
    ],
  },
}

const deletion: Bilingual = {
  ar: {
    title: 'طلب حذف البيانات',
    summary: 'كيف تطلب حذف بياناتك أو بيانات عميل محتمل أو بيانات جلبتها منصة مثل Meta أو Google، وماذا يحدث بعد ذلك.',
    sections: [
      { id: 'how', heading: 'كيف تطلب الحذف', paragraphs: ['نتعامل مع طلبات الحذف يدويًا عبر فريقنا، ولا يوجد نموذج إلكتروني لذلك حاليًا. اتبع الخطوات التالية:'], bullets: ['1. أرسل رسالة من البريد المرتبط بحسابك إلى {email}، بعنوان «طلب حذف بيانات».', '2. سنتحقق من حسابك أو من صلتك بالبيانات المطلوبة، وقد نطلب معلومة إضافية لتأكيد ذلك.', '3. حدّد ما تريد حذفه: حسابك كاملًا، أو عميلًا محتملًا بعينه، أو بيانات جلبتها منصة (مثل بيانات نماذج Meta أو حساب إعلاني مربوط).', '4. نؤكد لك كتابةً عند التنفيذ، أو نشرح ما لا يمكن حذفه ولماذا.'] },
      { id: 'what', heading: 'ما الذي نحذفه', bullets: ['حسابك وبياناتك الشخصية داخل المنصة.', 'بيانات عميل محتمل بعينه: ملفه ومحادثاته وملاحظاته ومواعيده وسجل نشاطه.', 'بيانات الاعتماد لأي منصة مربوطة، مع فصل الربط. وتستطيع مساحة العمل فصل أي تكامل بنفسها في أي وقت.', 'البيانات التي جلبتها منصة إعلانية ومحفوظة في مساحة عملك.'] },
      { id: 'leads', heading: 'إن كنت عميلًا محتملًا', paragraphs: ['بياناتك أدخلتها الشركة التي تواصلت معك أو وصلت إليها من إعلانها. اطلب الحذف منها أولًا. وإن تعذّر ذلك أو فضّلت مراسلتنا، اذكر في رسالتك رقم هاتفك أو بريدك كما استُخدم، واسم الشركة أو الإعلان إن عرفتهما، وسنساعدك في الوصول إلى الجهة المعنية.'] },
      { id: 'platforms', heading: 'بيانات المنصات الإعلانية', paragraphs: ['إن منحت «مهتم» صلاحية الوصول عبر Meta أو Google أو غيرهما، فإلغاء الصلاحية من حسابك في تلك المنصة يوقف وصولنا إليها. اطلب منا بعد ذلك حذف ما جلبناه سابقًا باتباع الخطوات أعلاه.'] },
      { id: 'limits', heading: 'ما قد يبقى', paragraphs: ['قد نحتفظ بما تلزمنا الأنظمة بحفظه، أو ما يلزم لحماية الخدمة من إساءة الاستخدام، وبقدر الحاجة وحدها. نخبرك بما بقي وسببه.'] },
      { id: 'contact', heading: 'التواصل', paragraphs: ['البريد: {email}.', 'الجهة: {company}.'] },
    ],
  },
  en: {
    title: 'Data deletion request',
    summary: 'How to ask for your data, a lead’s data, or data pulled in by a platform such as Meta or Google to be deleted, and what happens next.',
    sections: [
      { id: 'how', heading: 'How to ask', paragraphs: ['Our team handles deletion requests by hand; there is no online form for it at the moment. Follow these steps:'], bullets: ['1. Write from the email linked to your account to {email}, with the subject “Data deletion request”.', '2. We will verify your account, or your connection to the data requested, and may ask for one more detail to confirm it.', '3. Say what you want deleted: your whole account, one particular lead, or data a platform pulled in (such as Meta form data or a connected ad account).', '4. We confirm in writing when it is done, or explain what cannot be deleted and why.'] },
      { id: 'what', heading: 'What we delete', bullets: ['Your account and personal data inside the platform.', 'A particular lead’s data: profile, conversations, notes, appointments and activity history.', 'Credentials for any connected platform, and the connection itself. A workspace can disconnect any integration itself at any time.', 'Data an ad platform supplied that is stored in your workspace.'] },
      { id: 'leads', heading: 'If you are a lead', paragraphs: ['Your data was entered by the company that contacted you, or arrived from its advertising. Ask that company first. If that is not possible, or you would rather write to us, give your phone number or email as it was used, and the company or ad if you know them, and we will help you reach the party concerned.'] },
      { id: 'platforms', heading: 'Ad platform data', paragraphs: ['If you granted مهتم access through Meta, Google or another platform, removing the permission in your account on that platform stops our access. Then ask us to delete what we pulled earlier, following the steps above.'] },
      { id: 'limits', heading: 'What may remain', paragraphs: ['We may keep what the rules require us to keep, or what is needed to protect the service from abuse, and only as far as needed. We tell you what remained and why.'] },
      { id: 'contact', heading: 'Contact', paragraphs: ['Email: {email}.', 'Provider: {company}.'] },
    ],
  },
}

const security: Bilingual = {
  ar: {
    title: 'الأمان وحماية البيانات',
    summary: 'الضوابط الموجودة في المنتج فعلًا، وما لا ندّعيه.',
    sections: [
      { id: 'isolation', heading: 'عزل مساحات العمل', paragraphs: ['كل مساحة عمل لها بياناتها المنفصلة منطقيًا، وكل استعلام يُقيَّد بمساحة صاحبه. لا تظهر بيانات مساحة لمستخدم من أخرى، ولدينا اختبارات آلية تتحقق من ذلك.'] },
      { id: 'access', heading: 'الصلاحيات حسب الدور', paragraphs: ['لكل مستخدم دور وصلاحيات يحددها مسؤول مساحة العمل، ويمكن إخفاء شاشات وعناصر بعينها وحصر الوصول بمشاريع محددة. الشاشة التي لا يملك المستخدم صلاحيتها لا تظهر له، والخادم يرفض بياناتها كذلك.'] },
      { id: 'audit', heading: 'سجل التدقيق', paragraphs: ['تُسجَّل الأحداث الحساسة، مثل تغيير الصلاحيات وإعدادات الهوية وربط التكاملات، ليعرف المسؤول من فعل ماذا ومتى.'] },
      { id: 'secrets', heading: 'تشفير بيانات الاعتماد', paragraphs: ['مفاتيح الذكاء الاصطناعي وبيانات اعتماد التكاملات والمنصات الإعلانية وأسرار الربط تُخزَّن مشفّرة ولا تُعاد إلى المتصفح بعد حفظها.'] },
      { id: 'rate', heading: 'تحديد معدل الطلبات', paragraphs: ['نحدّ من عدد محاولات تسجيل الدخول والطلبات إلى الواجهة البرمجية وروابط التتبع والتقارير المشتركة ونقاط استقبال العملاء من المنصات، للحد من التخمين وإساءة الاستخدام.'] },
      { id: 'webhooks', heading: 'التحقق من الإشعارات الواردة', paragraphs: ['الإشعارات الواردة من منصات مثل واتساب ونقاط الاستقبال الخاصة تُفحص بتوقيعات سرية قبل قبولها.'] },
      { id: 'sessions', heading: 'حماية الجلسات', paragraphs: ['يستطيع المستخدم تغيير كلمة مروره وإنهاء جلساته على كل أجهزته. وتنتهي الجلسة بانتهاء الاشتراك أو إيقاف الحساب.'] },
      { id: 'sharing', heading: 'مشاركة التقارير', paragraphs: ['رابط التقرير اللحظي محميّ برمز طويل لا يمكن تخمينه، ويمكن حمايته بكلمة مرور وتحديد مدته وإيقافه أو استبداله في أي وقت. ويُحجب المال وبيانات الفريق عن التقارير المخصصة للشركاء.'] },
      { id: 'deployment', heading: 'بيئة التشغيل', paragraphs: ['التشفير أثناء النقل وجداول النسخ الاحتياطي ومراقبة الخوادم من مسؤوليات بيئة التشغيل، وسنوثّقها هنا عند الإطلاق الفعلي.'] },
      { id: 'claims', heading: 'ما لا ندّعيه', paragraphs: ['لا نحمل حاليًا شهادات مثل SOC 2 أو ISO 27001 أو PCI، ولا نصف المنصة بأنها معتمدة وفق GDPR أو أي نظام آخر. نكتفي بوصف الضوابط أعلاه، ونحدّث الصفحة حين يتغير الواقع.'] },
      { id: 'report', heading: 'الإبلاغ عن ثغرة', paragraphs: ['إن وجدت مشكلة أمنية، راسلنا على {email} مع وصف يكفي لإعادة إنتاجها، ولا تصل إلى بيانات لا تخصك.'] },
    ],
  },
  en: {
    title: 'Security and data protection',
    summary: 'The controls that exist in the product today, and what we do not claim.',
    sections: [
      { id: 'isolation', heading: 'Workspace isolation', paragraphs: ['Each workspace has its own, logically separate data, and every query is scoped to its owner’s workspace. One workspace’s data never appears to a user of another, and automated tests check it.'] },
      { id: 'access', heading: 'Role-based access', paragraphs: ['Every user has a role and permissions set by the workspace administrator; particular screens and elements can be hidden and access limited to certain projects. A screen a user lacks permission for is not shown, and the server refuses its data as well.'] },
      { id: 'audit', heading: 'Audit log', paragraphs: ['Sensitive events — permission changes, identity settings, integrations being connected — are recorded, so an administrator can see who did what and when.'] },
      { id: 'secrets', heading: 'Encrypted credentials', paragraphs: ['AI keys, integration and ad-platform credentials and connection secrets are stored encrypted and are not sent back to the browser once saved.'] },
      { id: 'rate', heading: 'Rate limiting', paragraphs: ['We limit sign-in attempts and requests to the API, tracking links, shared reports and the endpoints that receive leads from platforms, to curb guessing and abuse.'] },
      { id: 'webhooks', heading: 'Verifying inbound notifications', paragraphs: ['Notifications from platforms such as WhatsApp and from private intake endpoints are checked against secret signatures before they are accepted.'] },
      { id: 'sessions', heading: 'Session protection', paragraphs: ['A user can change their password and end their sessions on every device. A session ends when a subscription ends or an account is suspended.'] },
      { id: 'sharing', heading: 'Report sharing', paragraphs: ['A live report’s link is protected by a long token that cannot be guessed, can be protected by a password and a lifetime, and can be stopped or replaced at any time. Money and team data are withheld from reports made for partners.'] },
      { id: 'deployment', heading: 'Operating environment', paragraphs: ['Encryption in transit, backup schedules and server monitoring belong to the operating environment, and we will document them here at the actual launch.'] },
      { id: 'claims', heading: 'What we do not claim', paragraphs: ['We do not currently hold certifications such as SOC 2, ISO 27001 or PCI, and do not describe the platform as certified under GDPR or any other regime. We describe the controls above, and update this page when the facts change.'] },
      { id: 'report', heading: 'Reporting a vulnerability', paragraphs: ['If you find a security issue, write to {email} with enough detail to reproduce it, and please do not access data that is not yours.'] },
    ],
  },
}

const about: Bilingual = {
  ar: {
    title: 'عن مهتم',
    summary: 'من نحن، وماذا نبني، وكيف نفكر.',
    sections: [
      { id: 'what', heading: 'ما هي «مهتم»', paragraphs: ['«مهتم» منصة تشغيل للتسويق والمبيعات العقارية، قامت على فكرة واحدة: كل من اهتم بمشروعك فرصة قريبة، ومهمتنا أن تصل إليه قبل غيرك ولا تفرّط فيه. نريد أن يرى كل فريق عقاري، في أي لحظة، ما صنعه اهتمام الناس من فرص وصفقات.'] },
      { id: 'journey', heading: 'رحلة واحدة', paragraphs: ['وصل ← تواصل ← تأهّل ← موعد ← صفقة. كل ما نبنيه يخدم أن يمضي العميل في هذه الرحلة خطوة بعد خطوة.'] },
      { id: 'principles', heading: 'كيف نفكر', bullets: ['عربية أولًا: واجهة من اليمين إلى اليسار، لا مترجمة من لغة أخرى.', 'الصدق في الأرقام: ما لا نعرفه نقوله، وما هو توضيحي نوسمه.', 'فريق المبيعات أولًا: أقصر طريق بين العميل والفريق.', 'من الاهتمام إلى الإيراد: نريد أن يرى الفريق أثر جهده كاملًا.'] },
      { id: 'contact', heading: 'تواصل معنا', paragraphs: ['لطلب تجربة أو أي سؤال، تفضّل بزيارة صفحة التواصل.'] },
    ],
  },
  en: {
    title: 'About مهتم',
    summary: 'Who we are, what we build, and how we think.',
    sections: [
      { id: 'what', heading: 'What مهتم is', paragraphs: ['مهتم is an operating platform for real estate marketing and sales, built on one idea: everyone who showed interest in your project is an opportunity within reach, and our job is to get you to them before anyone else and never let them go. We want every real estate team to see, at any moment, what people’s interest turned into.'] },
      { id: 'journey', heading: 'One journey', paragraphs: ['Arrived → Contacted → Qualified → Viewing → Deal. Everything we build serves a lead moving along that journey, step by step.'] },
      { id: 'principles', heading: 'How we think', bullets: ['Arabic first: a right-to-left interface, not one translated from another language.', 'Honest numbers: what we do not know we say, and what is illustrative we label.', 'The sales team first: the shortest path between a lead and the team.', 'From interest to revenue: a team should see the full effect of its effort.'] },
      { id: 'contact', heading: 'Get in touch', paragraphs: ['To request a trial or ask anything, visit the contact page.'] },
    ],
  },
}

const contact: Bilingual = {
  ar: {
    title: 'التواصل',
    summary: 'كيف تصل إلينا، بحسب ما تحتاج.',
    sections: [
      { id: 'trial', heading: 'طلب تجربة', paragraphs: ['التجربة بطلب من فريقنا ولا يوجد تسجيل ذاتي بعد. راسلنا على {email} واذكر اسم شركتك وعدد فريق المبيعات والمنصات الإعلانية التي تستخدمها، وسنرتّب معك الخطوة التالية.'] },
      { id: 'support', heading: 'الدعم لمن لديه حساب', paragraphs: ['إن كان لديك حساب، ابدأ بمسؤول مساحة العمل في شركتك. وإن لم يحل المسألة، راسلنا على {email} مع اسم مساحة العمل ووصف ما حدث.'] },
      { id: 'password', heading: 'نسيت كلمة المرور', paragraphs: ['اختر «نسيت كلمة المرور» في شاشة الدخول واكتب بريدك؛ يصلك رابط صالح لساعة واحدة لتختار كلمة مرور جديدة بنفسك. لا تُرسل كلمات المرور بالبريد أبدًا. إن لم يصلك الرابط فراسلنا من البريد المرتبط بالحساب على {email}.'] },
      { id: 'privacy', heading: 'الخصوصية وحذف البيانات', paragraphs: ['لطلبات الاطلاع على البيانات أو حذفها، اتبع الخطوات في صفحة حذف البيانات.'] },
      { id: 'security', heading: 'الأمان', paragraphs: ['للإبلاغ عن ثغرة، راجع صفحة الأمان.'] },
      { id: 'company', heading: 'بيانات الجهة', paragraphs: ['الجهة: {company}.', 'البريد: {email}.', 'العنوان: {address}.'] },
    ],
  },
  en: {
    title: 'Contact',
    summary: 'How to reach us, depending on what you need.',
    sections: [
      { id: 'trial', heading: 'Request a trial', paragraphs: ['Trials are arranged by our team; there is no self-service sign-up yet. Write to {email} with your company’s name, the size of your sales team and the ad platforms you use, and we will arrange the next step with you.'] },
      { id: 'support', heading: 'Support for account holders', paragraphs: ['If you have an account, start with your workspace administrator. If that does not settle it, write to {email} with the workspace name and a description of what happened.'] },
      { id: 'password', heading: 'Forgotten password', paragraphs: ['Choose “Forgot password” on the sign-in screen and enter your email: you get a link, valid for one hour, to choose a new password yourself. Passwords are never sent by email. If the link does not arrive, write from the email linked to the account to {email}.'] },
      { id: 'privacy', heading: 'Privacy and data deletion', paragraphs: ['To ask to see or delete data, follow the steps on the data deletion page.'] },
      { id: 'security', heading: 'Security', paragraphs: ['To report a vulnerability, see the security page.'] },
      { id: 'company', heading: 'Provider details', paragraphs: ['Provider: {company}.', 'Email: {email}.', 'Address: {address}.'] },
    ],
  },
}

export const LEGAL = { privacy, terms, deletion, security, about, contact } as const
export type LegalKey = keyof typeof LEGAL
