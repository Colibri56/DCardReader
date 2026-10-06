# DCard AI Reader Backend

WordPress plugin: REST API backend for the "הקורא בקלפים" SPA frontend. Manages session state, drawn cards, AI dialogue (Claude or OpenAI) and the closing email. No frontend code here by design - the SPA already exists and talks to these endpoints.

## מבנה קבצים
```
dcard-ai-reader-backend/
├── dcard-ai-reader-backend.php      # bootstrap: hooks, activation, cron
├── agent-brain-instructions.md      # system prompt - identical for both providers
├── data/
│   └── cards-data.json              # 72 cards, converted from context/cards/cards-data.csv
└── includes/
    ├── class-dcard-activator.php    # creates wp_dcard_sessions on activation
    ├── class-dcard-db.php           # all reads/writes to the sessions table
    ├── class-dcard-settings.php     # Settings > DCard AI (provider toggle, API keys, closing texts)
    ├── class-dcard-cards.php        # loads cards-data.json, hands out single card records
    ├── class-dcard-ai-gateway.php   # routes to Claude/OpenAI, parses+validates the JSON contract
    ├── class-dcard-mailer.php       # transcript -> HTML -> wp_mail()
    ├── class-dcard-rate-limiter.php # per-IP throttling (public, unauthenticated endpoints)
    └── class-dcard-rest-controller.php  # the 4 REST endpoints
```

## התקנה
1. להעתיק את כל התיקייה `dcard-ai-reader-backend/` אל `wp-content/plugins/` באתר.
2. להפעיל את הפלאגין מתוך "תוספים" בלוח הבקרה - זה יוצר את הטבלה `wp_dcard_sessions`.
3. Settings > DCard AI: לבחור ספק (Claude/OpenAI), להזין מפתח API, ואופציונלית טקסטי סיום.
   - חלופה בטוחה יותר למפתחות: להגדיר קבועים ב-`wp-config.php` במקום להזין בטופס -
     `define('DCARD_ANTHROPIC_API_KEY', '...');` / `define('DCARD_OPENAI_API_KEY', '...');`
     קבועים אלה גוברים על מה שמוזן בהגדרות.
4. אם יש שינוי בתמונות/בתוכן הקלפים - לעדכן את `data/cards-data.json` (או להריץ מחדש את סקריפט ההמרה מה-CSV, ר' scripts/ בפרויקט הראשי).

## חוזה ה-API (namespace: `dcard/v1`)

| Endpoint | Payload | Response עיקרי |
|---|---|---|
| `POST /session/start` | `{ userName, userGender }` | `{ session_token, aiReply }` |
| `POST /session/card-selected` | `{ session_token, card_id }` | `{ success, aiReply, nextAction, endSession }` |
| `POST /session/turn` | `{ session_token, message }` | `{ aiReply, nextAction, showCardDraw, endSession }` |
| `POST /session/end` | `{ session_token, email }` | `{ emailSent, closingMessage }` |

כל התגובות מכילות את השדות שהתבקשו (session_token / success / aiReply) בתוספת כמה שדות עזר קטנים (`nextAction`, `showCardDraw`, `endSession`) שה-frontend יכול להשתמש בהם או פשוט להתעלם מהם.

## שתי סטיות מכוונות מהאפיון המקורי - וההיגיון מאחוריהן

1. **`/session/card-selected` גם קורא ל-AI ומחזיר `aiReply`, לא רק boolean.**
   לפי agent-brain-instructions.md (שלב ה), ברגע שהתמונה מוצגת הסוכן חייב לשאול קודם כל "מה עולה בך כשאת מסתכלת על הקלף?". אם ה-endpoint הזה רק היה מאמת ושומר את בחירת הקלף בלי לקרוא למודל, השאלה הזו פשוט לא הייתה נשאלת. ה-frontend יכול להתעלם מ-`aiReply` אם הוא לא רוצה להציג אותו כאן, אבל השדה קיים.

2. **`/session/turn` אוכף בשרת את מגבלת 15 האיטרציות.**
   לפי סעיף 3 של אותו קובץ, זו בפירוש אחריות האתר ("אכיפת המגבלה של 15 איטרציות ושל 3 קלפים"), לא של המודל - לכן ה-turn_count מנוהל ומוגבל צד-שרת, ולא מתבסס על מה שהמודל "חושב" שהוא הגיע אליו.

מעבר לשני אלה, הפלאגין בנוי בדיוק לפי מה שביקשת.

## לוגים

כל הלוגים של הפלאגין עוברים דרך **ה-logger של WooCommerce** (`wc_get_logger()`), ב-`class-dcard-logger.php` - **לא** דרך `error_log()` ולא דרך קובץ/תיקייה מותאמים אישית. זה מכוון: האתר הוא אתר WooCommerce בפרודקשן, וה-`debug.log` הראשי של וורדפרס לא אמור להתמלא בלוגים של פלאגין צד-שלישי.

- הלוגים מופיעים ב-**WooCommerce > Status > Logs** (או Marketing/כלים, תלוי בגרסה) - קובץ טקסט שוורדפרס/WooCommerce עצמם מנהלים, מסובבים ומגנים עליו. הפלאגין לא יוצר ולא נוגע בשום קובץ בעצמו.
- **אם WooCommerce לא פעיל, אין שום לוג בכלל.** `DCard_Logger` בודק `function_exists('wc_get_logger')` ומדלג בשקט אם הוא לא קיים - אין נפילה חזרה ל-`error_log()`. חשוב לדעת: על אתר שבו WooCommerce כבוי או שהופעל אחר כך, שום דבר לא יירשם עד שהוא יופעל.
- שני "מקורות" (source) נפרדים כדי שאפשר יהיה לסנן בממשק ה-Logs של WooCommerce:
  - `dcard-ai-usage` - שימוש בטוקנים ו-cache מ-Claude/OpenAI (`DCard_Logger::usage()`, נקרא אחרי כל קריאה מוצלחת ב-`class-dcard-ai-gateway.php`).
  - `dcard-ai-reader` - כל שאר הלוגים (שגיאות API, JSON לא תקין, Exception לא צפוי וכו').

## הערות אבטחה
- אין WP nonce חובה (ה-endpoints ציבוריים ואנונימיים לגמרי) - אם יתברר שה-SPA בכל זאת נטען מתוך עמוד וורדפרס מחובר, אפשר להוסיף `wp_localize_script` עם נאנס ולאכוף אותו ב-REST controller.
- הגנה עיקרית: `session_token` (UUID לא-מנוחש) + rate limiting לפי IP (20 session starts / 120 turns לשעה, ניתן לכוונון ב-class-dcard-rate-limiter.php).
- מפתחות ה-API לעולם לא חוזרים ב-response.

## פתרון תקלות: 502 Bad Gateway מ-Cloudflare ב-`/session/card-selected`

בוצעו שלושה שינויים ישירים בשביל זה:

1. **כל קריאה חיצונית עטופה ב-try/catch, בכל שכבה.** `DCard_AI_Gateway::run_turn()` עוטף את כל הלוגיקה שלו, וכל ה-callbacks ב-`class-dcard-rest-controller.php` עוברים עכשיו דרך מתודת `safely()` שתופסת כל `\Throwable`. שום שגיאה לא אמורה יותר לגרום ל-PHP Fatal Error שמפיל את הבקשה - היא תמיד תחזיר JSON מסודר עם קוד HTTP מתאים (400/404/409/429/500/502 לפי המקרה). נוספה גם רשת ביטחון גלובלית ב-`dcard-ai-reader-backend.php` (shutdown handler) שתופסת גם שגיאת fatal שקורית *מחוץ* ל-try/catch שלנו, ומחזירה JSON נקי במקום עמוד שבור.
2. **הטיימאאוט של הקריאות ל-Claude/OpenAI ירד מ-55 ל-28 שניות** (ניתן לשינוי דרך `dcard_request_timeout` filter). זה החשד המרכזי לגבי ה-502: **502 מ-Cloudflare כמעט תמיד אומר של-CF נמאס לחכות לתשובה מהשרת המקורי (origin timeout), לא בהכרח שגיאת PHP.** אם ה-timeout הפנימי שלנו (היה 55 שניות) ארוך יותר מהטיימאאוט של Cloudflare/הפרוקסי שמולו האתר יושב (בהרבה תצורות זה סביב 30-60 שניות), אז PHP עדיין "עובד" ומחכה לתשובה מ-Anthropic בזמן ש-Cloudflare כבר ויתר וסגר את החיבור - וזה בדיוק ה-502 שראית. הורדת הטיימאאוט הפנימי מתחת לזה גורמת לקוד *שלנו* להיכשל בצורה מבוקרת (JSON error) לפני שהפרוקסי מכריע בשבילנו.
3. **לוגים מפורטים** - כל כשל (שגיאת רשת, קוד HTTP לא תקין מהספק, JSON לא תקין מהמודל) נכתב עכשיו דרך ה-logger של WooCommerce (ר' "לוגים" למטה). כדי לראות מה קרה בפועל:
   - להיכנס ל-WooCommerce > Status > Logs, ולפתוח את הקובץ עם source `dcard-ai-reader` מהזמן שבו קיבלת את ה-502.
   - השורה תגיד בדיוק אם זו הייתה שגיאת timeout, מפתח API שגוי, תשובה לא תקינה מהמודל, או Exception לא צפוי (עם קובץ+שורה). אם WooCommerce לא פעיל באתר, אין כרגע שום לוג לבדוק - ר' ההערה בסעיף "לוגים".

**אם ה-502 ממשיך אחרי זה** - כמעט בטוח שזו בעיית timeout ברמת התשתית שקוד לא יכול לפתור לבד: צריך להעלות את `proxy_read_timeout`/`request_terminate_timeout` בצד השרת (Nginx/PHP-FPM) ואת ה-timeout של Cloudflare (Page Rule מותאם, או Enterprise plan) כך שיהיו **ארוכים** מהטיימאאוט הפנימי שלנו - לא לתקן קוד, אלא להתאים את שתי השכבות זו לזו. אם זה לא אפשרי בתשתית הקיימת, זה בדיוק התרחיש שבו כדאי לחזור לאופציה של backend חיצוני קטן (Vercel/Render) שנדונה בהתחלה, עם streaming response במקום בקשה סינכרונית ארוכה.

## פתרון תקלות: "Model returned invalid JSON" ב-debug.log

תוקן משני הכיוונים:

1. **חילוץ JSON חסין** ב-`extract_json_contract()` (class-dcard-ai-gateway.php): קודם מנסה לחלץ תוכן מתוך גדר ```json ... ``` אם קיימת (בכל מקום בטקסט, לא רק בהתחלה), ואם אין - סורק לאיתור ה-`{` הראשון וסופר עומק סוגריים (תוך התעלמות מסוגריים בתוך מחרוזות) עד שמוצא את ה-`}` התואם. כך גם משפט הקדמה/הסבר לפני או אחרי אובייקט ה-JSON כבר לא מפיל את הפענוח.
2. **תיקון נוסף שהתגלה תוך כדי**: `call_claude()` לקח בעבר את `content[0]['text']` באופן עיוור - אבל התשובה מ-Claude היא מערך של "בלוקים", ואם יש בלוק חשיבה (thinking) לפני בלוק הטקסט, `content[0]` הוא לא בהכרח הטקסט. עכשיו הקוד סורק את כל הבלוקים ולוקח את הראשון מסוג `text`.
3. **חיזוק agent-brain-instructions.md** (סעיף 10): נוספה הנחיה מוחלטת בראש הסעיף - "התו הראשון הוא `{`, התו האחרון הוא `}`, שום דבר אחר לא מותר" - וגם כלל מפורש ברשימת הכללים ובדיקת קבלה נוספת (#15). **חשוב:** יש שתי עותקים זהים של הקובץ הזה - `context/cards/agent-brain-instructions.md` (מקור, בפרויקט הראשי) ו-`outputs/dcard-ai-reader-backend/agent-brain-instructions.md` (מה שהפלאגין בפועל טוען). שניהם עודכנו ומאומתים כזהים - אם תערוך את אחד מהם ידנית בעתיד, תזכור לסנכרן את השני.

שני התיקונים משלימים זה את זה בכוונה: ההנחיה בפרומפט אמורה למנוע את התופעה מלכתחילה, אבל אי אפשר לסמוך אך ורק על ציות המודל - החילוץ החסין בקוד הוא רשת הביטחון שתמשיך לעבוד גם אם מודל (או ספק) עתידי לא מצייתים במדויק.

## פתרון תקלות: "תקוע 30 שניות ואז שגיאת חיבור" בשליפת קלף

**סיבת השורש**: עבור הקלף הראשון בכל שאלה, ה-frontend היה שולח **שתי בקשות ברצף** - `/session/card-selected` (שכבר גורם לקריאת AI מלאה כדי לשאול "מה עולה בך...") ומיד אחריה עוד `/session/turn` נפרד רק כדי לספר למודל מה השאלה של המשתמש (כי `/session/start` לא מקבל שאלה, וה-frontend הזה מדלג לגמרי על שלב הבהרת השאלה בצ'אט). בפועל זה שתי קריאות AI מלאות ברצף לפני שהמשתמש רואה תשובה - וזה בדיוק מה שדחף את זמן ההמתנה הכולל מעבר לטיימאאוט הפנימי (28 שניות לכל קריאה בודדת, אבל שתיים ברצף בקלות עוברות 30+ שניות).

**התיקון** (בשני הקצוות):
- **Backend** (`class-dcard-rest-controller.php`, `session_card_selected_inner`): מקבל עכשיו פרמטר אופציונלי `question`. אם הוא מגיע ועדיין אין `original_question` שמור בסשן, הוא נשמר ומוזן ישירות לתוך קריאת ה-AI *של אותה בקשה עצמה* - כך שהתשובה הראשונה כבר מבוססת על השאלה, בלי צורך בקריאה שנייה.
- **Frontend** (`elementor-code.html`): הקריאה הכפולה הוסרה. עבור הקלף הראשון, השאלה (`userQuestion.value`) נשלחת כשדה `question` בתוך אותה בקשת `/card-selected` יחידה, ותגובת ה-AI (`cardData.aiReply`) מוצגת ישירות - בדיוק כמו בקלף השני והשלישי.

**תוצאה**: קריאת AI אחת בלבד לכל קלף (כולל הראשון), ולכן זמן ההמתנה חצי ממה שהיה, והרבה יותר רחוק מגבול הטיימאאוט.

## מה עדיין פתוח
- **בדיקת מגבלות האחסון** (קריאות HTTPS יוצאות + max_execution_time) - עדיין לא אומת בפועל. כתבתי את ה-timeout של הקריאות ל-Claude/OpenAI כ-55 שניות (מתחת ל-360 שניות שציינת), אבל שווה לבדוק בפועל עם קריאה אמיתית לפני עלייה לאוויר.
- אין עדיין מנגנון "משתמש רשום" (`user_is_registered` קבוע כ-false) - כשיתווסף, `session_end` צריך לקבל את הפרמטר הזה ולבחור בין שני טקסטי הסיום בהתאם.
- ה-cron היומי מוחק סשנים completed אחרי 48 שעות וסשנים active שננטשו אחרי שעתיים - לשקול אם משך הזמן הזה מתאים.
