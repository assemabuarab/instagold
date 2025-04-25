<?php
// حماية إضافية ممكنة لاحقاً (مثل التحقق من الجلسة أو IP)

// الرابط الحقيقي للتحميل
$downloadUrl = 'https://dlll.apkm.app/insta/9.80F/InstaGold_v9.80F.apk';

// إعادة توجيه المستخدم إلى الرابط
header("Location: $downloadUrl");
exit;
?>
