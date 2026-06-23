<?php
header('Content-Type: text/html; charset=utf-8');

/* ✅ DB 연결 + 문자셋 */
$con = mysqli_connect("dbsrv.idcseoul.internal", "user1", "p@ssw0rd", "sqlDB");
if (!$con) {
  http_response_code(500);
  echo "<!doctype html><meta charset='utf-8'><p>MySQL 접속 실패: "
      . htmlspecialchars(mysqli_connect_error(), ENT_QUOTES, 'UTF-8') . "</p>";
  exit;
}
mysqli_set_charset($con, "utf8mb4");

/* ✅ 헬퍼 */
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
function valid_date_ymd($s) {
  if (!$s) return false;
  $d = DateTime::createFromFormat('Y-m-d', $s);
  return $d && $d->format('Y-m-d') === $s;
}

/* ✅ 입력값 수집 */
$userID    = $_POST["userID"]   ?? '';
$name      = $_POST["name"]     ?? '';
$birthYear = $_POST["birthYear"]?? '';
$addr      = $_POST["addr"]     ?? '';
$mobile1   = $_POST["mobile1"]  ?? '';
$mobile2   = $_POST["mobile2"]  ?? '';
$height    = $_POST["height"]   ?? '';
$mDATE     = $_POST["mDATE"]    ?? '';

/* ✅ 서버측 유효성 검사 */
$errors = [];
if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $userID))  $errors[] = "아이디 형식이 잘못되었습니다.";
if (!strlen(trim($name)))                            $errors[] = "이름을 입력하세요.";
if (!ctype_digit((string)$birthYear) ||
    (int)$birthYear < 1920 || (int)$birthYear > 2025) $errors[] = "출생년도는 1920~2025 사이 숫자여야 합니다.";
if (!strlen(trim($addr)))                            $errors[] = "지역을 입력하세요.";
if (!preg_match('/^01[016789]$/', $mobile1))         $errors[] = "휴대폰 국번은 010/011/016/017/018/019 중 하나여야 합니다.";
if (!preg_match('/^[0-9]{7,8}$/', $mobile2))         $errors[] = "휴대폰 번호는 하이픈 없이 7~8자리 숫자여야 합니다.";
if (!ctype_digit((string)$height) ||
    (int)$height < 50 || (int)$height > 250)         $errors[] = "신장은 50~250 범위의 숫자여야 합니다.";
if ($mDATE !== '' && !valid_date_ymd($mDATE))        $errors[] = "가입일 형식(YYYY-MM-DD)이 올바르지 않습니다.";

/* 🎯 기존 레코드 조회(비교용) */
$before = null;
if (empty($errors)) {
  $stmt = mysqli_prepare($con, "SELECT userID, name, birthYear, addr, mobile1, mobile2, height, mDATE FROM userTBL WHERE userID=?");
  mysqli_stmt_bind_param($stmt, 's', $userID);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  if ($res && mysqli_num_rows($res) > 0) {
    $before = mysqli_fetch_assoc($res);
  } else {
    $errors[] = "해당 아이디의 회원이 존재하지 않습니다.";
  }
  mysqli_free_result($res);
  mysqli_stmt_close($stmt);
}

/* 🧾 업데이트 실행 */
$ok = false;
$affected = 0;
if (empty($errors)) {
  $stmt = mysqli_prepare($con, "UPDATE userTBL
    SET name = ?, birthYear = ?, addr = ?, mobile1 = ?, mobile2 = ?, height = ?, mDATE = ?
    WHERE userID = ?");
  //   s         i             s         s          s          i           s            s
  mysqli_stmt_bind_param($stmt, 'sisssiss', $name, $birthYear, $addr, $mobile1, $mobile2, $height, $mDATE, $userID);
  $ok = mysqli_stmt_execute($stmt);
  $affected = mysqli_stmt_affected_rows($stmt);
  $sql_err = $ok ? '' : mysqli_stmt_error($stmt);
  mysqli_stmt_close($stmt);
}

/* 🔄 업데이트 후 레코드 재조회 */
$after = null;
if ($ok) {
  $stmt = mysqli_prepare($con, "SELECT userID, name, birthYear, addr, mobile1, mobile2, height, mDATE FROM userTBL WHERE userID=?");
  mysqli_stmt_bind_param($stmt, 's', $userID);
  mysqli_stmt_execute($stmt);
  $res = mysqli_stmt_get_result($stmt);
  if ($res) $after = mysqli_fetch_assoc($res);
  mysqli_free_result($res);
  mysqli_stmt_close($stmt);
}

mysqli_close($con);

/* 🧮 변경 항목 계산 */
$diffs = [];
if ($before && $after) {
  foreach (['name','birthYear','addr','mobile1','mobile2','height','mDATE'] as $k) {
    $b = (string)($before[$k] ?? '');
    $a = (string)($after[$k]  ?? '');
    if ($b !== $a) $diffs[$k] = [$b, $a];
  }
}
?>
<!doctype html>
<html lang="ko" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>회원 정보 수정 결과 - <?= $h($userID) ?></title>
  <style>
    :root{
      --bg:#0e1229; --card:#151b3a; --text:#eaf0ff; --muted:#9aa3c7;
      --accent:#7ca6ff; --accent2:#8df3ff; --ok:#29d398; --warn:#ffd166; --danger:#ff6b6b;
      --border:rgba(255,255,255,.12); --input:#0d1231; --shadow:0 16px 44px rgba(0,0,0,.38);
    }
    [data-theme="light"]{
      --bg:#f3f6ff; --card:#ffffff; --text:#1a2038; --muted:#586285;
      --accent:#4c7dff; --accent2:#3bd6ff; --ok:#10b981; --warn:#f59e0b; --danger:#ef4444;
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
    .app{width:min(980px,100%)}
    .nav{display:flex; justify-content:space-between; align-items:center; margin-bottom:14px}
    .title{display:flex; gap:10px; align-items:center}
    .title h1{margin:0; font-size:22px}
    .badge{display:inline-flex; gap:8px; align-items:center; padding:8px 12px; border-radius:999px;
      background:linear-gradient(90deg,var(--accent),var(--accent2)); color:#0c1233; font-weight:900;}
    .btn{
      appearance:none; border:1px solid var(--border); background:transparent; color:var(--text);
      padding:10px 14px; border-radius:12px; cursor:pointer; font-weight:700;
    }
    .btn:hover{filter:brightness(1.08)}
    .card{background:linear-gradient(180deg,rgba(255,255,255,.03),rgba(255,255,255,.01)), var(--card);
      border:1px solid var(--border); border-radius:22px; box-shadow:var(--shadow); overflow:hidden;}
    .head{display:flex; justify-content:space-between; align-items:center; padding:18px 18px 0}
    .wrap{padding:18px}
    .status{display:flex; align-items:center; gap:10px; padding:12px 14px; border-radius:12px;
      border:1px solid var(--border); background:var(--input); margin-bottom:14px}
    .status.ok{border-color:rgba(41,211,152,.35)} .status.err{border-color:rgba(255,107,107,.35)}
    .grid{display:grid; grid-template-columns:1fr; gap:14px}
    .diff-table{width:100%; border-collapse:separate; border-spacing:0 10px}
    .diff-table thead th{padding:12px; text-align:left; border-bottom:1px solid var(--border)}
    .row{background:var(--input); border:1px solid var(--border); border-radius:14px}
    .row th, .row td{padding:12px; vertical-align:top}
    .changed{background:linear-gradient(90deg, rgba(41,211,152,.15), transparent)}
    .muted{color:var(--muted)}
    .footer{display:flex; justify-content:space-between; align-items:center; padding:0 18px 18px}
    .link{color:var(--text); text-decoration:none; border:1px solid var(--border); padding:10px 12px; border-radius:10px}
    .go{background:linear-gradient(90deg,var(--accent),var(--accent2)); color:#0c1233; border:none; padding:10px 14px; border-radius:12px; font-weight:900; cursor:pointer}
    .toast{position:fixed; right:16px; bottom:16px; display:flex; flex-direction:column; gap:10px; z-index:50}
    .toast .msg{background:var(--card); border:1px solid var(--border); color:var(--text); padding:12px 14px; border-radius:12px; box-shadow:var(--shadow); animation:slide .2s ease}
    @keyframes slide{from{opacity:0; transform:translateY(8px)} to{opacity:1; transform:translateY(0)}}
  </style>
</head>
<body>
  <div class="app">
    <nav class="nav">
      <div class="title">
        <h1>💾 회원 정보 수정 결과</h1>
        <span class="badge">ID: <?= $h($userID) ?></span>
      </div>
      <div class="actions">
        <button class="btn" id="toggle">🌗 테마 전환</button>
      </div>
    </nav>

    <section class="card" role="region" aria-label="수정 결과">
      <div class="head">
        <h2>📊 처리 요약</h2>
      </div>
      <div class="wrap">
        <?php if (!empty($errors)): ?>
          <div class="status err">⛔ 처리 실패 — <?= $h(implode(' / ', $errors)) ?></div>
        <?php elseif (!$ok): ?>
          <div class="status err">⛔ 처리 실패 — <?= $h($sql_err ?? '알 수 없는 오류') ?></div>
        <?php else: ?>
          <div class="status ok">✅ 수정 완료 — <?= $affected === 0 ? '값 변화는 없었습니다.' : '데이터가 성공적으로 업데이트되었습니다.' ?></div>
        <?php endif; ?>

        <div class="grid">
          <div>
            <table class="diff-table" aria-label="수정 전후 비교">
              <thead>
                <tr>
                  <th class="muted">필드</th>
                  <th>수정 전</th>
                  <th></th>
                  <th>수정 후</th>
                </tr>
              </thead>
              <tbody>
                <?php
                  $labels = [
                    'name'=>'이름','birthYear'=>'출생년도','addr'=>'지역',
                    'mobile1'=>'휴대폰 국번','mobile2'=>'휴대폰 번호','height'=>'신장','mDATE'=>'가입일'
                  ];
                  foreach ($labels as $k=>$label):
                    $b = $before[$k] ?? '';
                    $a = $after[$k]  ?? ($ok ? '' : ($before[$k] ?? ''));
                    $changed = isset($diffs[$k]);
                ?>
                <tr class="row <?= $changed ? 'changed' : '' ?>">
                  <th><?= $h($label) ?></th>
                  <td><?= $h($b) ?></td>
                  <td><?= $changed ? '→' : '—' ?></td>
                  <td><?= $h($a) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <p class="muted" style="margin-top:12px">
              <?= !empty($diffs) ? '총 ' . count($diffs) . '개 필드가 변경되었습니다.' : '변경된 항목이 없습니다.' ?>
            </p>
          </div>
        </div>

        <div class="footer">
          <div style="display:flex; gap:8px; align-items:center;">
            <a class="link" href="main.html">🏠 초기 화면</a>
            <a class="link" href="select.php">📋 회원 목록</a>
          </div>
          <div style="display:flex; gap:8px;">
            <a class="link" href="update.php?userID=<?= $h(urlencode($userID)) ?>">✏️ 다시 수정</a>
            <button class="go" onclick="location.href='insert.php'">➕ 신규 등록</button>
          </div>
        </div>
      </div>
    </section>
  </div>

  <!-- 토스트 -->
  <div class="toast" id="toast"></div>

  <script>
    // 🌗 테마 전환
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

    // 초기 상태 토스트
    <?php if (!empty($errors)): ?>
      toast('⛔ 입력값을 확인해주세요.');
    <?php elseif (!$ok): ?>
      toast('⛔ 업데이트 중 오류가 발생했습니다.');
    <?php else: ?>
      toast('✅ 업데이트가 완료되었습니다.');
    <?php endif; ?>
  </script>
</body>
</html>
