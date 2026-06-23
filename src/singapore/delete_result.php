<?php
// delete_result.php
// 결과 페이지: POST로 전달된 userID를 CSRF 검증 후 안전하게 삭제하고, 화려한 UI로 결과를 표시합니다.

header('Content-Type: text/html; charset=utf-8');
session_start();

/* ===============================
   공용 헬퍼
================================= */
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
function toastScript($msg) {
  return "<script>setTimeout(()=>toast(" . json_encode($msg, JSON_UNESCAPED_UNICODE) . "),200);</script>";
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
  $title = '잘못된 요청';
  $status = 'error';
  $msg = 'POST 요청만 허용됩니다.';
  $detail = '잘못된 접근 방식입니다.';
  goto OUTPUT;
}

/* ===============================
   입력 파라미터 + CSRF 검증
================================= */
$userID = trim($_POST['userID'] ?? '');
$csrf   = $_POST['csrf'] ?? '';

if ($userID === '') {
  $title = '입력 오류';
  $status = 'error';
  $msg = 'userID 값이 비어 있습니다.';
  $detail = '폼에서 아이디가 누락되었습니다.';
  goto OUTPUT;
}

if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
  $title = '보안 오류';
  $status = 'error';
  $msg = 'CSRF 토큰 검증에 실패했습니다.';
  $detail = '세션 만료 또는 새로고침/중복 제출일 수 있습니다. 처음 화면으로 돌아가 다시 시도하세요.';
  // 토큰 재사용 방지: 어쨌든 파기
  unset($_SESSION['csrf']);
  goto OUTPUT;
}
// 토큰 1회성 사용
unset($_SESSION['csrf']);

/* ===============================
   DB 연결 + 문자셋
   ⚠︎ 비밀번호는 실제 환경과 일치시켜 주세요.
================================= */
$mysqli = @mysqli_connect("dbsrv.idcseoul.internal", "user1", "P@ssw0rd", "sqlDB"); // ← 환경에 맞게
if (!$mysqli) {
  $title = 'DB 연결 실패';
  $status = 'error';
  $msg = 'MySQL 접속에 실패했습니다.';
  $detail = mysqli_connect_error();
  goto OUTPUT;
}
mysqli_set_charset($mysqli, "utf8mb4");

/* ===============================
   삭제 대상 조회 (이름 등 표시용)
================================= */
$selectName = null;
if ($stmt = mysqli_prepare($mysqli, "SELECT name FROM userTBL WHERE userID = ?")) {
  mysqli_stmt_bind_param($stmt, 's', $userID);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  if ($res && ($row = mysqli_fetch_assoc($res))) {
    $selectName = $row['name'] ?? null;
  }
  if ($res) mysqli_free_result($res);
  mysqli_stmt_close($stmt);
}

/* ===============================
   안전 삭제 (Prepared Statement)
================================= */
$affected = 0;
$dbErr = '';
if ($stmt = mysqli_prepare($mysqli, "DELETE FROM userTBL WHERE userID = ?")) {
  mysqli_stmt_bind_param($stmt, 's', $userID);
  mysqli_stmt_execute($stmt);
  $affected = mysqli_stmt_affected_rows($stmt);
  $dbErr = mysqli_stmt_error($stmt);
  mysqli_stmt_close($stmt);
} else {
  $dbErr = mysqli_error($mysqli);
}

mysqli_close($mysqli);

/* ===============================
   결과 메시지 결정
================================= */
if ($affected > 0) {
  $title  = '회원 삭제 완료';
  $status = 'success';
  $msg    = '요청하신 회원이 성공적으로 삭제되었습니다.';
  $detail = '';
} else {
  // 이미 삭제되었거나 존재하지 않는 경우 포함
  $title  = '삭제 대상 없음';
  $status = 'warn';
  $msg    = '해당 아이디의 회원이 존재하지 않거나 이미 삭제되었습니다.';
  $detail = $dbErr ?: '일치하는 레코드가 없습니다.';
}

/* ===============================
   공통 출력 (화려한 UI)
================================= */
OUTPUT:
?>
<!doctype html>
<html lang="ko" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $h($title) ?> - <?= $h($userID) ?></title>
  <style>
    :root{
      --bg:#0e1229; --card:#151b3a; --text:#eaf0ff; --muted:#9aa3c7;
      --accent:#7ca6ff; --accent2:#8df3ff; --danger:#ff6b6b; --warn:#ffd166;
      --success:#34d399; --border:rgba(255,255,255,.12); --input:#0d1231; --shadow:0 16px 44px rgba(0,0,0,.38);
    }
    [data-theme="light"]{
      --bg:#f3f6ff; --card:#ffffff; --text:#1a2038; --muted:#586285;
      --accent:#4c7dff; --accent2:#3bd6ff; --danger:#ef4444; --warn:#f59e0b;
      --success:#10b981; --border:rgba(10,20,60,.14); --input:#f7faff; --shadow:0 12px 28px rgba(32,40,94,.12);
    }
    *{box-sizing:border-box}
    body{
      margin:0; background:
        radial-gradient(1000px 520px at 12% -10%, rgba(124,166,255,.18), transparent 60%),
        radial-gradient(900px 600px at 110% 0%, rgba(141,243,255,.18), transparent 60%),
        var(--bg);
      color:var(--text);
      font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Noto Sans KR',Pretendard,sans-serif;
      min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px;
    }
    .app{width:min(880px,100%)}
    .nav{display:flex; justify-content:space-between; align-items:center; margin-bottom:14px}
    .title{display:flex; gap:10px; align-items:center}
    .title h1{margin:0; font-size:22px}
    .badge{
      display:inline-flex; gap:8px; align-items:center; padding:8px 12px; border-radius:999px;
      background:linear-gradient(90deg,var(--accent),var(--accent2)); color:#0c1233; font-weight:900;
    }
    .btn{
      appearance:none; border:1px solid var(--border); background:transparent; color:var(--text);
      padding:10px 14px; border-radius:12px; cursor:pointer; font-weight:700;
    }
    .btn:hover{filter:brightness(1.08)}
    .card{
      background:linear-gradient(180deg,rgba(255,255,255,.03),rgba(255,255,255,.01)), var(--card);
      border:1px solid var(--border); border-radius:22px; box-shadow:var(--shadow); overflow:hidden;
    }
    .head{display:flex; justify-content:space-between; align-items:center; padding:18px 18px 0}
    .wrap{padding:18px}
    .footer{display:flex; justify-content:space-between; align-items:center; padding:0 18px 18px; color:var(--muted)}
    .pill{
      display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:999px; font-weight:900;
      border:1px solid var(--border);
    }
    .ok{background:linear-gradient(90deg,var(--success),#b9f6d0); color:#053b2a; border:none}
    .warn{background:linear-gradient(90deg,var(--warn),#ffe8ac); color:#4a2f00; border:none}
    .err{background:linear-gradient(90deg,var(--danger),#ffb3b3); color:#2b0b0b; border:none}
    .grid{display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:12px}
    .field{background:var(--input); border:1px solid var(--border); border-radius:14px; padding:12px}
    .label{display:block; font-size:12px; color:var(--muted); margin-bottom:6px}
    .value{font-weight:800}
    .actions{display:flex; gap:10px; flex-wrap:wrap}
    .link{color:var(--text); text-decoration:none; border:1px solid var(--border); padding:10px 12px; border-radius:10px}
    .ghost{background:transparent; border:1px solid var(--border); color:var(--text); padding:12px 18px; border-radius:12px; font-weight:900; cursor:pointer}
    .toast{position:fixed; right:16px; bottom:16px; display:flex; flex-direction:column; gap:10px; z-index:50}
    .toast .msg{background:var(--card); border:1px solid var(--border); color:var(--text); padding:12px 14px; border-radius:12px; box-shadow:var(--shadow); animation:slide .2s ease}
    details{background:var(--input); border:1px solid var(--border); border-radius:14px; padding:12px; margin-top:12px}
    summary{cursor:pointer; font-weight:800}
    @keyframes slide{from{opacity:0; transform:translateY(8px)} to{opacity:1; transform:translateY(0)}}
  </style>
</head>
<body>
  <div class="app">
    <nav class="nav">
      <div class="title">
        <h1><?= $status==='success' ? '✅ 삭제 완료' : ($status==='warn' ? '⚠️ 안내' : '⛔ 오류') ?></h1>
        <span class="badge">ID: <?= $h($userID) ?></span>
      </div>
      <div class="actions">
        <button class="btn" id="toggle">🌗 테마 전환</button>
      </div>
    </nav>

    <section class="card" role="region" aria-label="삭제 결과">
      <div class="head">
        <h2><?= $h($title) ?></h2>
        <?php if ($status==='success'): ?>
          <div class="pill ok">🎉 성공</div>
        <?php elseif ($status==='warn'): ?>
          <div class="pill warn">🔎 확인 필요</div>
        <?php else: ?>
          <div class="pill err">💥 오류</div>
        <?php endif; ?>
      </div>

      <div class="wrap">
        <p style="margin-top:0; font-weight:700;"><?= $h($msg) ?></p>

        <div class="grid">
          <div class="field">
            <span class="label">👤 아이디</span>
            <div class="value"><?= $h($userID) ?></div>
          </div>
          <div class="field">
            <span class="label">🧑 이름</span>
            <div class="value"><?= $h($selectName ?? '정보 없음') ?></div>
          </div>
        </div>

        <?php if (!empty($detail)): ?>
          <details>
            <summary>🔧 상세 정보 펼치기</summary>
            <pre style="white-space:pre-wrap; margin:10px 0 0"><?= $h($detail) ?></pre>
          </details>
        <?php endif; ?>

        <div class="actions" style="margin-top:14px">
          <button class="ghost" onclick="history.back()">↩️ 이전 페이지</button>
          <a class="link" href="main.html">🏠 초기 화면</a>
          <a class="link" href="select.php">📋 회원 목록</a>
        </div>
      </div>

      <div class="footer">
        <span style="color:var(--muted)">이 작업은 되돌릴 수 없습니다.</span>
        <span>Tip: <span style="font-family:monospace">Alt+←</span> 뒤로가기</span>
      </div>
    </section>
  </div>

  <!-- 토스트 -->
  <div class="toast" id="toast"></div>

  <script>
    // 🌗 테마 토글
    const root = document.documentElement;
    document.getElementById('toggle').addEventListener('click', ()=>{
      root.dataset.theme = (root.dataset.theme === 'light') ? 'dark' : 'light';
      toast(root.dataset.theme === 'light' ? '라이트 테마로 변경되었습니다.' : '다크 테마로 변경되었습니다.');
    });

    // 🔔 토스트
    const toastBox = document.getElementById('toast');
    function toast(text, ttl=2200){
      const el = document.createElement('div');
      el.className = 'msg'; el.textContent = text;
      toastBox.appendChild(el);
      setTimeout(()=>{ el.style.opacity='0'; el.style.transform='translateY(6px)'; }, ttl-300);
      setTimeout(()=>{ toastBox.removeChild(el); }, ttl);
    }

    // 결과에 따른 토스트 알림
    <?php
      if ($status === 'success') {
        echo "toast('삭제가 완료되었습니다.');";
      } elseif ($status === 'warn') {
        echo "toast('대상 없음: 아이디를 확인하세요.');";
      } else {
        echo "toast('오류가 발생했습니다. 상세 정보를 확인하세요.');";
      }
    ?>
  </script>
  <?= ($status==='success') ? toastScript('🎉 성공적으로 삭제되었습니다!') : '' ?>
</body>
</html>
