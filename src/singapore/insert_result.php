<?php
// insert_result.php
// 신규 회원 입력 처리 결과 페이지 (보안 강화 + 화려한 UI)

header('Content-Type: text/html; charset=utf-8');
session_start();

/* ===============================
   공용 헬퍼
================================= */
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
function toastScript($msg) {
  return "<script>setTimeout(()=>toast(" . json_encode($msg, JSON_UNESCAPED_UNICODE) . "),200);</script>";
}

/* ===============================
   메서드 검증
================================= */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
  $title  = '잘못된 요청';
  $status = 'error';
  $msg    = 'POST 요청만 허용됩니다.';
  $detail = '요청 메서드가 올바르지 않습니다.';
  $payload = [];
  goto OUTPUT;
}

/* ===============================
   입력 수집 + 서버측 유효성 검사
   - 클라이언트 검증을 통과했더라도 서버에서 재검증
================================= */
$userID    = trim($_POST['userID']   ?? '');
$name      = trim($_POST['name']     ?? '');
$birthYear = trim($_POST['birthYear']?? '');
$addr      = trim($_POST['addr']     ?? '');
$mobile1   = trim($_POST['mobile1']  ?? '');
$mobile2   = trim($_POST['mobile2']  ?? '');
$height    = trim($_POST['height']   ?? '');
$mDate     = date('Y-m-d'); // 표준 YYYY-MM-DD

$errors = [];

// 정규식/범위 검증
if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $userID))   $errors['userID']    = '아이디는 영문/숫자/밑줄 3~20자입니다.';
if ($name === '' || mb_strlen($name) > 40)            $errors['name']      = '이름을 1~40자로 입력하세요.';
if (!preg_match('/^\d{4}$/', $birthYear))             $errors['birthYear'] = '출생년도는 YYYY 형식입니다.';
else {
  $by = (int)$birthYear;
  if ($by < 1920 || $by > 2025)                       $errors['birthYear'] = '출생년도는 1920~2025 사이여야 합니다.';
}
if ($addr === '')                                      $errors['addr']      = '지역을 입력하세요.';
if (!preg_match('/^01[016789]$/', $mobile1))          $errors['mobile1']   = '국번은 010/011/016/017/018/019 중 하나여야 합니다.';
if (!preg_match('/^[0-9]{7,8}$/', $mobile2))          $errors['mobile2']   = '번호는 하이픈 없이 7~8자리입니다.';
if (!preg_match('/^\d+$/', $height))                  $errors['height']    = '신장은 숫자여야 합니다.';
else {
  $ht = (int)$height;
  if ($ht < 50 || $ht > 250)                          $errors['height']    = '신장은 50~250 사이여야 합니다.';
}

// 선택적 CSRF (폼에 csrf가 추가된 경우에만 검증)
// ※ 현재 제공된 폼에는 csrf 필드가 없으므로, 추후 폼에 <input name="csrf"> 추가 시 활성화됩니다.
if (isset($_POST['csrf'])) {
  $csrf = $_POST['csrf'];
  if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
    $errors['csrf'] = 'CSRF 토큰 검증 실패 (세션 만료 또는 중복 제출).';
  }
  unset($_SESSION['csrf']);
}

$payload = [
  'userID'    => $userID,
  'name'      => $name,
  'birthYear' => $birthYear,
  'addr'      => $addr,
  'mobile1'   => $mobile1,
  'mobile2'   => $mobile2,
  'height'    => $height,
  'mDate'     => $mDate,
];

/* ===============================
   에러가 있으면 DB 접속 없이 결과 출력
================================= */
if (!empty($errors)) {
  $title  = '입력값 오류';
  $status = 'error';
  $msg    = '입력값을 다시 확인해주세요.';
  $detail = implode("\n", array_map(fn($k,$v)=>"$k: $v", array_keys($errors), $errors));
  goto OUTPUT;
}

/* ===============================
   DB 연결 + 문자셋
   ⚠︎ 비밀번호는 실제 환경과 일치시켜 주세요.
================================= */
$mysqli = @mysqli_connect("dbsrv.idcseoul.internal", "user1", "p@ssw0rd", "sqlDB"); // ← 환경에 맞게
if (!$mysqli) {
  $title  = 'DB 연결 실패';
  $status = 'error';
  $msg    = 'MySQL 접속에 실패했습니다.';
  $detail = mysqli_connect_error();
  goto OUTPUT;
}
mysqli_set_charset($mysqli, "utf8mb4");

/* ===============================
   아이디 중복 체크
================================= */
$exist = false;
if ($stmt = mysqli_prepare($mysqli, "SELECT 1 FROM userTBL WHERE userID = ?")) {
  mysqli_stmt_bind_param($stmt, 's', $userID);
  mysqli_stmt_execute($stmt);
  mysqli_stmt_store_result($stmt);
  $exist = mysqli_stmt_num_rows($stmt) > 0;
  mysqli_stmt_close($stmt);
}

if ($exist) {
  $title  = '중복 아이디';
  $status = 'warn';
  $msg    = '이미 존재하는 아이디입니다.';
  $detail = '다른 아이디를 사용해주세요.';
  mysqli_close($mysqli);
  goto OUTPUT;
}

/* ===============================
   안전 삽입 (Prepared Statement)
   컬럼 순서를 명시해 안정성 확보
================================= */
$sql = "INSERT INTO userTBL
        (userID, name, birthYear, addr, mobile1, mobile2, height, mDate)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

$ok = false;
$dbErr = '';

if ($stmt = mysqli_prepare($mysqli, $sql)) {
  $by = (int)$birthYear;
  $ht = (int)$height;
  mysqli_stmt_bind_param($stmt, 'ssisssis',
    $userID, $name, $by, $addr, $mobile1, $mobile2, $ht, $mDate
  );
  $ok = mysqli_stmt_execute($stmt);
  if (!$ok) $dbErr = mysqli_stmt_error($stmt);
  mysqli_stmt_close($stmt);
} else {
  $dbErr = mysqli_error($mysqli);
}
mysqli_close($mysqli);

/* ===============================
   결과 메시지
================================= */
if ($ok) {
  $title  = '신규 회원 등록 완료';
  $status = 'success';
  $msg    = '데이터가 성공적으로 입력되었습니다.';
  $detail = '';
} else {
  $title  = '데이터 입력 실패';
  $status = 'error';
  $msg    = '회원 입력 중 오류가 발생했습니다.';
  $detail = $dbErr ?: '알 수 없는 오류';
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
  <title><?= $h($title) ?> - <?= $h($payload['userID'] ?? '') ?></title>
  <style>
    :root{
      --bg:#0e1229; --card:#151b3a; --text:#eaf0ff; --muted:#9aa3c7;
      --accent:#7ca6ff; --accent2:#8df3ff; --danger:#ff6b6b; --warn:#ffd166; --success:#29d398;
      --border:rgba(255,255,255,.12); --input:#0d1231; --shadow:0 16px 44px rgba(0,0,0,.38);
    }
    [data-theme="light"]{
      --bg:#f3f6ff; --card:#ffffff; --text:#1a2038; --muted:#586285;
      --accent:#4c7dff; --accent2:#3bd6ff; --danger:#ef4444; --warn:#f59e0b; --success:#10b981;
      --border:rgba(10,20,60,.14); --input:#f7faff; --shadow:0 12px 28px rgba(32,40,94,.12);
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
    .app{width:min(920px,100%)}
    .nav{display:flex; justify-content:space-between; align-items:center; margin-bottom:14px}
    .title{display:flex; gap:10px; align-items:center}
    .title h1{margin:0; font-size:22px}
    .badge{
      display:inline-flex; gap:8px; align-items:center; padding:8px 12px; border-radius:999px;
      background:linear-gradient(90deg,var(--accent),var(--accent2)); color:#0c1233; font-weight:900;
    }
    .btn{appearance:none; border:1px solid var(--border); background:transparent; color:var(--text); padding:10px 14px; border-radius:12px; cursor:pointer; font-weight:700;}
    .btn:hover{filter:brightness(1.08)}
    .card{
      background:linear-gradient(180deg,rgba(255,255,255,.03),rgba(255,255,255,.01)), var(--card);
      border:1px solid var(--border); border-radius:22px; box-shadow:var(--shadow); overflow:hidden;
    }
    .head{display:flex; justify-content:space-between; align-items:center; padding:18px 18px 0}
    .wrap{padding:18px}
    .footer{display:flex; justify-content:space-between; align-items:center; padding:0 18px 18px; color:var(--muted)}
    .pill{display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:999px; font-weight:900; border:1px solid var(--border)}
    .ok{background:linear-gradient(90deg,var(--success),#b9f6d0); color:#053b2a; border:none}
    .warn{background:linear-gradient(90deg,var(--warn),#ffe8ac); color:#4a2f00; border:none}
    .err{background:linear-gradient(90deg,var(--danger),#ffb3b3); color:#2b0b0b; border:none}
    .grid{display:grid; grid-template-columns:repeat(2,1fr); gap:12px; margin-top:12px}
    @media (max-width:760px){ .grid{grid-template-columns:1fr} }
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
        <h1><?= $status==='success' ? '🎉 등록 성공' : ($status==='warn' ? '⚠️ 안내' : '⛔ 오류') ?></h1>
        <span class="badge">ID: <?= $h($payload['userID'] ?? '') ?></span>
      </div>
      <div class="actions">
        <button class="btn" id="toggle">🌗 테마 전환</button>
      </div>
    </nav>

    <section class="card" role="region" aria-label="신규 회원 입력 결과">
      <div class="head">
        <h2><?= $h($title) ?></h2>
        <?php if ($status==='success'): ?>
          <div class="pill ok">✅ 성공</div>
        <?php elseif ($status==='warn'): ?>
          <div class="pill warn">🔎 확인 필요</div>
        <?php else: ?>
          <div class="pill err">💥 오류</div>
        <?php endif; ?>
      </div>

      <div class="wrap">
        <p style="margin-top:0; font-weight:700;"><?= $h($msg) ?></p>

        <!-- 입력값 요약 -->
        <div class="grid">
          <div class="field"><span class="label">👤 아이디</span><div class="value"><?= $h($payload['userID'] ?? '') ?></div></div>
          <div class="field"><span class="label">🧑 이름</span><div class="value"><?= $h($payload['name'] ?? '') ?></div></div>
          <div class="field"><span class="label">🎂 출생년도</span><div class="value"><?= $h($payload['birthYear'] ?? '') ?></div></div>
          <div class="field"><span class="label">🗺️ 지역</span><div class="value"><?= $h($payload['addr'] ?? '') ?></div></div>
          <div class="field"><span class="label">☎️ 휴대폰</span><div class="value"><?= $h(($payload['mobile1'] ?? '').'-'.($payload['mobile2'] ?? '')) ?></div></div>
          <div class="field"><span class="label">📏 신장</span><div class="value"><?= $h($payload['height'] ?? '') ?> cm</div></div>
          <div class="field"><span class="label">🗓️ 가입일</span><div class="value"><?= $h($payload['mDate'] ?? '') ?></div></div>
          <div class="field"><span class="label">🧾 상태</span><div class="value"><?= $status==='success'?'성공':($status==='warn'?'안내':'오류') ?></div></div>
        </div>

        <?php if (!empty($detail)): ?>
          <details>
            <summary>🔧 상세 정보 펼치기</summary>
            <pre style="white-space:pre-wrap; margin:10px 0 0"><?= $h($detail) ?></pre>
          </details>
        <?php endif; ?>

        <div class="actions" style="margin-top:14px">
          <a class="link" href="main.html">🏠 초기 화면</a>
          <a class="link" href="select.php">📋 회원 목록</a>
          <button class="ghost" onclick="history.back()">↩️ 이전 페이지</button>
        </div>
      </div>

      <div class="footer">
        <span style="color:var(--muted)">정확한 정보 입력을 권장합니다.</span>
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

    // 결과별 첫 토스트
    <?php
      if ($status === 'success') {
        echo "toast('신규 회원이 등록되었습니다. 환영합니다! 🎉');";
      } elseif ($status === 'warn') {
        echo "toast('이미 존재하는 아이디입니다. 다른 아이디를 사용해주세요.');";
      } else {
        echo "toast('입력 처리 중 오류가 발생했습니다. 상세 정보를 확인하세요.');";
      }
    ?>
  </script>
  <?= ($status==='success') ? toastScript('🎉 성공적으로 입력되었습니다!') : '' ?>
</body>
</html>
