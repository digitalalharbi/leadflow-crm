export interface Insight {
  slug: string
  title: string
  summary: string
  sections: Array<{ heading: string; body: string }>
}

/** Written to be useful to someone running a sales team, with no invented numbers. A real example replaces an argument when one is verified. */
export const INSIGHTS: Record<'ar' | 'en', Insight[]> = {
  ar: [
    {
      slug: 'first-response',
      title: 'لماذا تضيع الفرصة في الدقائق الأولى',
      summary: 'الاهتمام يبرد بسرعة. ما الذي يجعل أول رد يتأخر، وكيف يُبنى نظام لا يعتمد على التذكر.',
      sections: [
        { heading: 'التأخر ليس كسلًا', body: 'في أغلب الفرق لا يتأخر الرد لأن المندوب لا يهتم، بل لأن العميل وصل إلى مكان لا يراه فيه: جدول، أو بريد، أو رسالة في مجموعة. حين يصل العميل إلى قائمة واضحة باسم مسؤول محدد، يصبح السؤال: من التالي؟ لا: هل وصل أحد؟' },
        { heading: 'ثلاثة أشياء تصنع الفرق', body: 'إسناد فوري لشخص بعينه، وتنبيه يصله حيث يعمل، ومهلة ظاهرة تقول متى يصبح الأمر متأخرًا. وإذا انقضت المهلة يعرف من هو فوقه، قبل أن يسأل العميل عن شركة أخرى.' },
        { heading: 'قِس الوسيط لا المتوسط', body: 'عميل واحد رُدّ عليه بعد ثلاثة أيام يفسد المتوسط ويخفي ما يحدث. راقب الوسيط لتعرف اليوم العادي، وراقب النسبة المئوية 95 لتعرف أسوأ ما يحدث لعملائك.' },
      ],
    },
    {
      slug: 'real-estate-funnel',
      title: 'قمع العقار ليس قمع الإعلانات',
      summary: 'النقرة ليست اهتمامًا، والاهتمام ليس حجزًا. كيف تقرأ المراحل من الوصول حتى الصفقة.',
      sections: [
        { heading: 'كل مرحلة سؤال مختلف', body: 'بين عميل وصل وعميل أتم الصفقة: هل تواصلنا؟ هل رد؟ هل هو مؤهل ماليًا وزمنيًا؟ هل يريد زيارة؟ هل زار؟ هل حجز؟ جمعها في رقم واحد يخفي أين يتسرب العملاء.' },
        { heading: 'اعرف أين يتوقفون', body: 'إذا كان أغلبهم يتوقف قبل أول رد فالمشكلة في السرعة. وإذا توقفوا بعد الزيارة فالمشكلة في المنتج أو في المتابعة. لكل مكان علاج مختلف، وصرف مزيد على الإعلان لا يعالج أيًا منهما.' },
        { heading: 'اربط الصفقة بمصدرها', body: 'تكلفة العميل وحدها تخدع: مصدر رخيص لا يُغلق أغلى من مصدر غالٍ يُغلق. حين ترتبط الصفقة بالحملة التي جاءت منها يصبح قرار الميزانية قرارًا لا تخمينًا.' },
      ],
    },
    {
      slug: 'follow-up-systems',
      title: 'المتابعة نظام، لا ذاكرة',
      summary: 'ما الذي يُؤتمت وما الذي يبقى لإنسان، ومتى يجب أن تتوقف المتابعة.',
      sections: [
        { heading: 'أتمت التذكير لا العلاقة', body: 'الآلة ممتازة في تذكّر الموعد التالي وفي إنشاء المهمة وتنبيه المسؤول. أما الحديث مع شخص يقرر شراء بيت فيبقى لإنسان. الأتمتة الجيدة تعيد المندوب إلى العميل في اللحظة المناسبة.' },
        { heading: 'متى تتوقف', body: 'أسوأ ما يفعله نظام متابعة أن يذكّر فريقك بعميل اشترى، أو يراسل شخصًا طلب ألا يراسله أحد. يجب أن تتوقف كل خطوة عند الصفقة والخسارة وإلغاء الاشتراك، حتى لو أعيد استيراد العميل لاحقًا.' },
        { heading: 'ابدأ صغيرًا', body: 'ثلاث قواعد تعمل أفضل من ثلاثين لا يفهمها أحد: إسناد عند الوصول، وتنبيه عند تأخر الرد، ومهمة بعد الزيارة. جرّبها على عميل واحد قبل تشغيلها.' },
      ],
    },
  ],
  en: [
    {
      slug: 'first-response',
      title: 'Why the opportunity is lost in the first minutes',
      summary: 'Interest cools quickly. What delays a first reply, and how to build a system that does not rely on memory.',
      sections: [
        { heading: 'Delay is rarely laziness', body: 'In most teams a reply is late because the lead arrived somewhere nobody was looking: a sheet, an inbox, a group chat. When it lands on one named person\'s list, the question becomes who is next, not whether anyone came in.' },
        { heading: 'Three things make the difference', body: 'Immediate assignment to one person, an alert where they work, and a visible deadline. When it passes, the person above them knows, before the customer asks another company.' },
        { heading: 'Measure the median, not the average', body: 'One lead answered after three days ruins the average and hides what is happening. Watch the median for the normal day and the 95th percentile for the worst your customers meet.' },
      ],
    },
    {
      slug: 'real-estate-funnel',
      title: 'A real-estate funnel is not an ad funnel',
      summary: 'A click is not interest and interest is not a reservation. How to read the stages from arrival to sale.',
      sections: [
        { heading: 'Each stage is a different question', body: 'Between arriving and buying: did we reach them, did they reply, are they qualified on money and timing, do they want a visit, did they visit, did they reserve? One number hides where people leak.' },
        { heading: 'Know where they stop', body: 'If most stop before a first reply, the problem is speed. After the visit, it is the product or the follow-up. Each has its own cure, and more ad spend cures neither.' },
        { heading: 'Tie the sale to its source', body: 'Cost per lead alone misleads: a cheap source that never closes costs more than an expensive one that does. With the sale tied to its campaign, a budget decision stops being a guess.' },
      ],
    },
    {
      slug: 'follow-up-systems',
      title: 'Follow-up is a system, not a memory',
      summary: 'What to automate, what stays with a person, and when follow-up must stop.',
      sections: [
        { heading: 'Automate the reminder, not the relationship', body: 'A machine is excellent at the next date, the task and the alert. Talking to someone deciding on a home stays with a person. Good automation returns the rep to the customer at the right moment.' },
        { heading: 'When to stop', body: 'The worst a system can do is remind your team about someone who bought, or write to someone who asked not to be written to. Every step must stop at a sale, a loss or an unsubscribe, even if the lead is imported again later.' },
        { heading: 'Start small', body: 'Three rules work better than thirty nobody understands: assign on arrival, alert on a late reply, a task after a visit. Try them on one lead before turning them on.' },
      ],
    },
  ],
}
