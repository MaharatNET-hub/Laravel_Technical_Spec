# نشر ديمو مراجعة التقديمات على السيرفر

السيرفر `159.223.136.60` — نفس نمط الأنظمة الثانية: حاوية Docker على **localhost فقط** و**nginx الموجود** ينشرها على دومين فرعي مع HTTPS.

| المكوّن | التفاصيل |
|---|---|
| التطبيق | حاوية على `127.0.0.1:3002` (3000 = 4jobs، 3001 = hlabook) |
| الحماية | اسم مستخدم + كلمة سر (وثائق العميل سرّية) |
| الذاكرة | حد أقصى 700MB — لا يوجد build ثقيل (Node فقط) |

---

## 1) DNS
عند مزوّد الدومين أضف سجل:

| Type | Name | Value |
|---|---|---|
| A | `review` (مثلاً) | `159.223.136.60` |

## 2) جلب الكود

من GitHub (مستودع خاص):
```bash
cd /root && git clone https://github.com/MaharatNET-hub/submittal-review-demo.git submittal-review
```
ملف العميل `submittal.pdf` **مش موجود على GitHub** (سرّي) — انسخه مرة وحدة من جهازك:
```
scp "$env:USERPROFILE\Desktop\submittal-review-demo\submittal.pdf" root@159.223.136.60:/root/submittal-review/
```

أو بدل GitHub: رفع ملف مضغوط من جهازك
من PowerShell على جهازك:
```
scp "$env:USERPROFILE\Desktop\submittal-review-demo.tar.gz" root@159.223.136.60:/root/
```

## 3) على السيرفر
```bash
ssh root@159.223.136.60
mkdir -p /root/submittal-review && cd /root/submittal-review
tar xzf /root/submittal-review-demo.tar.gz
cp .env.example .env
nano .env        # غيّر DEMO_PASSWORD لكلمة سر قوية
docker compose up -d --build
curl -I -u demo:كلمة_السر http://127.0.0.1:3002   # لازم 200
```

## 4) nginx + HTTPS
```bash
sed 's/DOMAIN/review.example.com/' deploy/submittal-review.conf > /etc/nginx/sites-available/submittal-review
ln -s /etc/nginx/sites-available/submittal-review /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
certbot --nginx -d review.example.com
```
(استبدل `review.example.com` بالدومين الفعلي في الأمرين.)

افتح `https://review.example.com` ← بيطلب اسم المستخدم وكلمة السر.

---

## تحديث لاحق
```bash
cd /root/submittal-review && git pull && docker compose up -d --build
```
أو ارفع tar جديد وفكّه بنفس المجلد، ثم:
```bash
cd /root/submittal-review && docker compose up -d --build
```

## إيقاف / حذف بعد انتهاء الديمو
```bash
cd /root/submittal-review && docker compose down
rm /etc/nginx/sites-enabled/submittal-review && systemctl reload nginx
```
