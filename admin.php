<?php
require_once __DIR__ . '/config.php';
requireAdminLogin();
date_default_timezone_set('Asia/Jakarta');

$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'add') {
        $nim = trim($_POST['nim'] ?? '');
        $nama = trim($_POST['nama'] ?? '');
        $kelas = trim($_POST['kelas'] ?? '');
        $jurusan = trim($_POST['jurusan'] ?? '');

        if (mb_strlen($nim) < 3 || $nama === '') {
            $message = 'NIM (minimal 3 karakter) dan Nama wajib diisi.';
            $messageType = 'error';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO mahasiswa (nim, nama, kelas, jurusan) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$nim, $nama, $kelas, $jurusan]);
                $message = "Mahasiswa \"{$nama}\" ({$nim}) berhasil ditambahkan.";
                $messageType = 'success';
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $message = "NIM {$nim} sudah terdaftar.";
                } else {
                    $message = 'Gagal menambahkan: ' . $e->getMessage();
                }
                $messageType = 'error';
            }
        }
    } elseif ($act === 'delete') {
        $nim = trim($_POST['nim'] ?? '');
        try {
            // Hapus dalam satu transaksi supaya benar-benar "hapus
            // mahasiswa + riwayat presensinya" seperti pesan yang
            // ditampilkan (sebelumnya hanya baris mahasiswa yang
            // terhapus, riwayat presensi lama tertinggal sebagai data
            // yatim kalau tabel tidak diset ON DELETE CASCADE).
            $pdo->beginTransaction();
            $stmtPresensi = $pdo->prepare('DELETE FROM presensi WHERE nim = ?');
            $stmtPresensi->execute([$nim]);
            $stmtMhs = $pdo->prepare('DELETE FROM mahasiswa WHERE nim = ?');
            $stmtMhs->execute([$nim]);
            $pdo->commit();
            $message = "Data mahasiswa NIM {$nim} dihapus (beserta riwayat presensinya).";
            $messageType = 'success';
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = 'Gagal menghapus: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($act === 'reset_hari_ini') {
        $stmt = $pdo->prepare('DELETE FROM presensi WHERE tanggal = ?');
        $stmt->execute([date('Y-m-d')]);
        $message = 'Presensi hari ini berhasil direset.';
        $messageType = 'success';
    }

    // Redirect supaya refresh halaman tidak mengulang submit form (pola PRG)
    header('Location: admin.php?msg=' . urlencode($message) . '&type=' . $messageType);
    exit;
}

if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
    $requestedType = $_GET['type'] ?? 'info';
    $messageType = in_array($requestedType, ['success', 'error', 'info'], true) ? $requestedType : 'info';
}

$mahasiswaList = $pdo->query('SELECT * FROM mahasiswa ORDER BY nama ASC')->fetchAll();

$today = date('Y-m-d');
$stmtToday = $pdo->prepare(
    'SELECT nim, nama, kelas, waktu FROM presensi WHERE tanggal = ? ORDER BY waktu DESC'
);
$stmtToday->execute([$today]);
$presensiHariIni = $stmtToday->fetchAll();

$totalMahasiswa = count($mahasiswaList);
$totalHadir = count($presensiHariIni);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Kelola Data - Presensi Kelas</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet" />
<style>
  :root {
    --background: 0 0% 0%;
    --foreground: 0 0% 100%;
    --muted-foreground: 0 0% 65%;
    --card: 0 0% 5%;
    --border: 0 0% 20%;
    --hero-subtitle: 210 17% 95%;
    --accent: 152 55% 55%;
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html { scroll-behavior: smooth; }
  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    font-weight: 400;
    background: hsl(var(--background));
    color: hsl(var(--foreground));
    min-height: 100vh;
  }
  table, th, td, .row-person, form.inline-form, .field input, button {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
  }
  a { color: inherit; }
  .font-serif { font-family: 'Instrument Serif', Georgia, serif; }

  /* ---------- liquid glass ---------- */
  .liquid-glass {
    background: rgba(255,255,255,0.01);
    background-blend-mode: luminosity;
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    border: none;
    box-shadow: inset 0 1px 1px rgba(255,255,255,0.1);
    position: relative;
    overflow: hidden;
  }
  .liquid-glass::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    padding: 1.4px;
    background: linear-gradient(180deg,
      rgba(255,255,255,0.45) 0%, rgba(255,255,255,0.15) 20%,
      rgba(255,255,255,0) 40%, rgba(255,255,255,0) 60%,
      rgba(255,255,255,0.15) 80%, rgba(255,255,255,0.45) 100%);
    -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
    pointer-events: none;
  }

  /* ---------- entrance animation ---------- */
  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(var(--rise, 20px)); }
    to { opacity: 1; transform: translateY(0); }
  }
  .reveal { opacity: 0; animation: fadeUp 0.6s ease-out forwards; }

  /* ---------- navbar ---------- */
  .navbar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 24px; position: relative; z-index: 20;
  }
  @media (min-width: 768px) { .navbar { padding: 16px 112px; } }
  .navbar .left { display: flex; align-items: center; gap: 20px; }
  @media (min-width: 768px) { .navbar .left { gap: 60px; } }
  .brand { display: flex; align-items: center; gap: 10px; }
  .brand .mark {
    width: 28px; height: 28px; border-radius: 8px;
    background: linear-gradient(135deg, hsl(var(--accent)), hsl(var(--foreground)));
    display: flex; align-items: center; justify-content: center;
    color: #000; font-size: 13px; font-weight: 700;
  }
  .brand span { font-size: 20px; font-weight: 700; letter-spacing: -0.02em; }
  .navlinks { display: none; gap: 4px; align-items: center; }
  @media (min-width: 768px) { .navlinks { display: flex; } }
  .navlinks a {
    text-decoration: none; font-size: 14px; color: hsl(var(--muted-foreground));
    padding: 8px 12px; border-radius: 8px; transition: color 0.2s, background 0.2s;
  }
  .navlinks a:hover, .navlinks a.active { color: hsl(var(--foreground)); background: rgba(255,255,255,0.06); }
  .btn-solid {
    background: hsl(var(--foreground)); color: hsl(var(--background));
    border-radius: 8px; font-size: 14px; font-weight: 600; padding: 9px 18px;
    text-decoration: none; border: none; cursor: pointer; display: inline-flex;
    align-items: center; gap: 8px; transition: opacity 0.2s;
  }
  .btn-solid:hover { opacity: 0.85; }

  /* ---------- hero ---------- */
  .hero {
    position: relative; overflow: hidden; padding-bottom: 90px;
  }
  .hero-video-wrap {
    position: relative; width: 100vw; margin-left: calc(-50vw + 50%);
    aspect-ratio: 21 / 9; max-height: 60vh; overflow: hidden;
  }
  .hero-video-wrap video {
    position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover;
    filter: brightness(0.55);
  }
  .hero-overlay {
    position: absolute; inset: 0;
    background: linear-gradient(180deg, rgba(0,0,0,0.35) 0%, rgba(0,0,0,0.55) 55%, hsl(var(--background)) 100%);
  }
  .hero-content {
    position: relative; z-index: 10; margin-top: -180px;
    display: flex; flex-direction: column; align-items: center; text-align: center;
    padding: 0 16px;
  }
  .tag-pill {
    display: inline-flex; align-items: center; gap: 10px;
    padding: 8px 12px; border-radius: 10px; margin-bottom: 24px;
  }
  .tag-pill .badge {
    background: hsl(var(--foreground)); color: hsl(var(--background));
    border-radius: 6px; font-size: 13px; font-weight: 500; padding: 2px 8px;
  }
  .tag-pill .txt { font-size: 14px; font-weight: 500; color: hsl(var(--muted-foreground)); }

  h1.hero-title {
    font-size: 40px; font-weight: 500; letter-spacing: -2px; line-height: 1.15;
    font-family: 'Inter', sans-serif;
    margin-bottom: 14px;
  }
  @media (min-width: 768px) { h1.hero-title { font-size: 60px; } }
  h1.hero-title .accent { font-family: 'Instrument Serif', Georgia, serif; font-style: italic; font-weight: 400; }

  .hero-subtitle {
    font-size: 17px; line-height: 24px; opacity: 0.9;
    color: hsl(var(--hero-subtitle)); margin-bottom: 32px; max-width: 480px;
  }

  .hero-stats { display: flex; gap: 14px; flex-wrap: wrap; justify-content: center; margin-bottom: 32px; }
  .stat-chip {
    padding: 14px 22px; border-radius: 14px; text-align: left; min-width: 150px;
  }
  .stat-chip .num { font-size: 26px; font-weight: 700; }
  .stat-chip .label { font-size: 12px; color: hsl(var(--muted-foreground)); margin-top: 2px; }

  .hero-cta {
    background: hsl(var(--foreground)); color: hsl(var(--background));
    border-radius: 999px; padding: 14px 32px; font-size: 15px; font-weight: 500;
    text-decoration: none; display: inline-flex; align-items: center; gap: 8px;
    transition: transform 0.15s ease;
  }
  .hero-cta:hover { transform: scale(1.03); }
  .hero-cta:active { transform: scale(0.98); }

  /* ---------- main content ---------- */
  .content { max-width: 900px; margin: 0 auto; padding: 0 20px 90px; position: relative; z-index: 10; }

  .banner {
    padding: 13px 16px; margin-bottom: 26px; border-radius: 12px; border-left: 3px solid;
    font-size: 13.5px; display: flex; align-items: center; gap: 10px;
    background: hsl(var(--card)); border: 1px solid hsl(var(--border)); border-left-width: 3px;
  }
  .banner.success { border-left-color: #34d399; color: #86efac; }
  .banner.error { border-left-color: #f87171; color: #fca5a5; }
  .banner.info { border-left-color: #7dd3fc; color: #bae6fd; }

  .card-section {
    background: hsl(var(--card)); border: 1px solid hsl(var(--border));
    border-radius: 20px; padding: 24px; margin-bottom: 24px;
  }
  .card-head {
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;
    margin-bottom: 18px;
  }
  .card-head h2 { font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
  .card-head h2 i { color: hsl(var(--accent)); font-size: 13px; }
  .card-head .aux { font-size: 12px; color: hsl(var(--muted-foreground)); }

  form.inline-form { display: flex; flex-direction: column; gap: 12px; max-width: 520px; }
  .field { display: flex; flex-direction: column; gap: 5px; }
  .field label { font-size: 12px; color: hsl(var(--muted-foreground)); }
  .field input {
    padding: 10px 12px; border-radius: 10px; border: 1px solid hsl(var(--border));
    background: rgba(255,255,255,0.03); color: hsl(var(--foreground)); font-size: 14px;
    font-family: 'Inter', sans-serif;
  }
  .field input:focus { outline: none; border-color: hsl(var(--accent)); }
  form.inline-form button {
    align-self: flex-start; margin-top: 4px; padding: 10px 22px; border: none;
    border-radius: 999px; background: hsl(var(--foreground)); color: hsl(var(--background));
    font-weight: 600; cursor: pointer; font-size: 13.5px; display: inline-flex; align-items: center; gap: 8px;
    transition: opacity 0.2s;
  }
  form.inline-form button:hover { opacity: 0.85; }

  .table-scroll { max-height: 380px; overflow-y: auto; padding: 2px; }
  .glass-list { display: flex; flex-direction: column; gap: 8px; }
  .glass-list-head {
    display: flex; align-items: center; padding: 0 18px; margin-bottom: 2px;
    color: hsl(var(--muted-foreground)); font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.06em;
  }
  .glass-row {
    border-radius: 16px; padding: 12px 18px; display: flex; align-items: center; gap: 14px;
    transition: background 0.2s ease, transform 0.15s ease;
  }
  .glass-row:hover { background: rgba(255,255,255,0.035); }
  .col { display: flex; flex-direction: column; }
  .col-person { flex: 1.4; min-width: 0; }
  .col-kelas { flex: 0.7; }
  .col-jurusan { flex: 1; }
  .col-waktu { flex: 0.7; }
  .col-action { flex: 0 0 auto; margin-left: auto; }
  .col .val { font-size: 13.5px; color: hsl(var(--foreground)); font-weight: 500; }
  .col .val.mono-time { font-variant-numeric: tabular-nums; font-weight: 600; }
  .col-truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

  .row-person { display: flex; align-items: center; gap: 12px; min-width: 0; }
  .row-person .avatar {
    width: 34px; height: 34px; border-radius: 999px; flex-shrink: 0;
    background: rgba(255,255,255,0.04); border: 2px solid hsl(var(--foreground));
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 700; font-family: 'Inter', sans-serif;
    backdrop-filter: blur(4px);
  }
  .row-person .meta { display: flex; flex-direction: column; line-height: 1.35; min-width: 0; }
  .row-person .meta .name {
    font-weight: 600; letter-spacing: -0.01em; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  }
  .row-person .meta .sub { font-size: 12px; color: hsl(var(--muted-foreground)); font-variant-numeric: tabular-nums; }

  @media (max-width: 620px) {
    .col-jurusan { display: none; }
    .glass-list-head .col-jurusan { display: none; }
  }

  .del-btn {
    background: rgba(248,113,113,0.08); color: #fca5a5; border: 1px solid rgba(248,113,113,0.3);
    border-radius: 10px; padding: 7px 11px; font-size: 12px; cursor: pointer;
    backdrop-filter: blur(4px); transition: background 0.2s;
  }
  .del-btn:hover { background: rgba(248,113,113,0.18); }

  .reset-btn {
    background: transparent; color: hsl(var(--muted-foreground)); border: 1px solid hsl(var(--border));
    border-radius: 8px; padding: 7px 14px; font-size: 11.5px; cursor: pointer; transition: color 0.2s, border-color 0.2s;
  }
  .reset-btn:hover { color: hsl(var(--foreground)); border-color: hsl(var(--foreground)); }

  .empty-note { color: hsl(var(--muted-foreground)); font-size: 13px; padding: 18px 0; text-align: center; }

  @media (max-width: 560px) {
    .hero-content { margin-top: -140px; }
    h1.hero-title { font-size: 32px; }
  }
</style>
</head>
<body>
  <header class="navbar">
    <div class="left">
      <a href="dashboard_admin.php" class="brand">
        <span class="mark">P</span>
        <span>Presensi<span style="opacity:.5">Kelas</span></span>
      </a>
      <nav class="navlinks">
        <a href="dashboard_admin.php">Beranda</a>
        <a href="admin.php" class="active">Kelola Data</a>
      </nav>
    </div>
    <a href="logout.php" class="btn-solid"><i class="fas fa-right-from-bracket"></i> Logout</a>
  </header>

  <section class="hero">
    <div class="hero-video-wrap">
      <video autoplay muted loop playsinline>
        <source src="https://d8j0ntlcm91z4.cloudfront.net/user_38xzZboKViGWJOttwIXH07lWA1P/hf_20260307_083826_e938b29f-a43a-41ec-a153-3d4730578ab8.mp4" type="video/mp4" />
      </video>
      <div class="hero-overlay"></div>
    </div>

    <div class="hero-content" id="heroContent">
      <div class="tag-pill liquid-glass reveal" style="--rise:10px; animation-delay:0s;">
        <span class="badge">Admin</span>
        <span class="txt">Panel Kelola Mahasiswa &amp; Presensi</span>
      </div>

      <h1 class="hero-title reveal" style="--rise:20px; animation-delay:0.1s;">
        Kelola Data.<br />
        <span class="accent">Presensi Kelas.</span>
      </h1>

      <p class="hero-subtitle reveal" style="--rise:20px; animation-delay:0.2s;">
        Tambah mahasiswa, pantau kehadiran hari ini,<br />
        dan kelola seluruh data presensi dari satu halaman.
      </p>

      <div class="hero-stats reveal" style="--rise:20px; animation-delay:0.3s;">
        <div class="stat-chip liquid-glass">
          <div class="num"><?= $totalMahasiswa ?></div>
          <div class="label">Total mahasiswa</div>
        </div>
        <div class="stat-chip liquid-glass">
          <div class="num"><?= $totalHadir ?></div>
          <div class="label">Hadir hari ini &middot; <?= htmlspecialchars($today) ?></div>
        </div>
      </div>

      <a href="#tambah" class="hero-cta reveal" style="--rise:20px; animation-delay:0.4s;">
        <i class="fas fa-user-plus"></i> Tambah Mahasiswa
      </a>
    </div>
  </section>

  <main class="content">
    <?php if ($message): ?>
      <div class="banner <?= htmlspecialchars($messageType) ?>">
        <i class="fas <?= $messageType === 'success' ? 'fa-check-circle' : ($messageType === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle') ?>"></i>
        <?= htmlspecialchars($message) ?>
      </div>
    <?php endif; ?>

    <section class="card-section">
      <div class="card-head">
        <h2><i class="fas fa-clipboard-check"></i> Presensi Hari Ini</h2>
        <form method="post" onsubmit="return confirm('Reset semua presensi hari ini?');">
          <input type="hidden" name="act" value="reset_hari_ini" />
          <button type="submit" class="reset-btn"><i class="fas fa-rotate-left"></i> Reset</button>
        </form>
      </div>
      <div class="table-scroll">
        <?php if (empty($presensiHariIni)): ?>
          <div class="empty-note">Belum ada yang presensi hari ini.</div>
        <?php else: ?>
          <div class="glass-list-head">
            <span class="col-person">Mahasiswa</span>
            <span class="col-kelas">Kelas</span>
            <span class="col-waktu">Waktu</span>
          </div>
          <div class="glass-list">
            <?php foreach ($presensiHariIni as $p):
              $initial = mb_strtoupper(mb_substr($p['nama'], 0, 1));
            ?>
              <div class="glass-row liquid-glass">
                <div class="col col-person">
                  <div class="row-person">
                    <div class="avatar"><?= htmlspecialchars($initial) ?></div>
                    <div class="meta">
                      <span class="name"><?= htmlspecialchars($p['nama']) ?></span>
                      <span class="sub"><?= htmlspecialchars($p['nim']) ?></span>
                    </div>
                  </div>
                </div>
                <div class="col col-kelas"><span class="val col-truncate"><?= htmlspecialchars($p['kelas']) ?></span></div>
                <div class="col col-waktu"><span class="val mono-time"><?= htmlspecialchars(date('H:i:s', strtotime($p['waktu']))) ?></span></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="card-section" id="tambah">
      <div class="card-head">
        <h2><i class="fas fa-user-plus"></i> Tambah Mahasiswa</h2>
      </div>
      <form class="inline-form" method="post">
        <input type="hidden" name="act" value="add" />
        <div class="field"><label for="f-nim">NIM</label><input id="f-nim" type="text" name="nim" placeholder="mis. 2210101" maxlength="20" required /></div>
        <div class="field"><label for="f-nama">Nama</label><input id="f-nama" type="text" name="nama" placeholder="Nama lengkap" maxlength="100" required /></div>
        <div class="field"><label for="f-kelas">Kelas</label><input id="f-kelas" type="text" name="kelas" placeholder="mis. TI-A" maxlength="50" /></div>
        <div class="field"><label for="f-jurusan">Jurusan</label><input id="f-jurusan" type="text" name="jurusan" placeholder="mis. Teknik Informatika" maxlength="100" /></div>
        <button type="submit"><i class="fas fa-plus"></i> Tambah</button>
      </form>
    </section>

    <section class="card-section">
      <div class="card-head">
        <h2><i class="fas fa-users"></i> Daftar Mahasiswa</h2>
        <span class="aux"><?= $totalMahasiswa ?> orang</span>
      </div>
      <div class="table-scroll">
        <?php if (empty($mahasiswaList)): ?>
          <div class="empty-note">Belum ada mahasiswa. Tambahkan lewat form di atas.</div>
        <?php else: ?>
          <div class="glass-list-head">
            <span class="col-person">Mahasiswa</span>
            <span class="col-kelas">Kelas</span>
            <span class="col-jurusan">Jurusan</span>
          </div>
          <div class="glass-list">
            <?php foreach ($mahasiswaList as $m):
              $initial = mb_strtoupper(mb_substr($m['nama'], 0, 1));
            ?>
              <div class="glass-row liquid-glass">
                <div class="col col-person">
                  <div class="row-person">
                    <div class="avatar"><?= htmlspecialchars($initial) ?></div>
                    <div class="meta">
                      <span class="name"><?= htmlspecialchars($m['nama']) ?></span>
                      <span class="sub"><?= htmlspecialchars($m['nim']) ?></span>
                    </div>
                  </div>
                </div>
                <div class="col col-kelas"><span class="val col-truncate"><?= htmlspecialchars($m['kelas']) ?></span></div>
                <div class="col col-jurusan"><span class="val col-truncate"><?= htmlspecialchars($m['jurusan']) ?></span></div>
                <div class="col col-action">
                  <form method="post" onsubmit="return confirm('Hapus <?= htmlspecialchars(addslashes($m['nama'])) ?>? Riwayat presensinya juga akan terhapus.');">
                    <input type="hidden" name="act" value="delete" />
                    <input type="hidden" name="nim" value="<?= htmlspecialchars($m['nim']) ?>" />
                    <button type="submit" class="del-btn"><i class="fas fa-trash"></i></button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </main>

  <script>
    // Parallax + fade ringan pada hero saat scroll (padanan sederhana dari
    // useScroll/useTransform Framer Motion di spek, tanpa dependency React).
    const heroContent = document.getElementById('heroContent');
    const heroVideoWrap = document.querySelector('.hero-video-wrap video');
    const heroSection = document.querySelector('.hero');

    function onScroll() {
      const rect = heroSection.getBoundingClientRect();
      const start = 0;
      const end = heroSection.offsetHeight;
      const progress = Math.min(Math.max((start - rect.top) / end, 0), 1);

      heroContent.style.transform = `translateY(${progress * -80}px)`;
      heroContent.style.opacity = String(1 - Math.min(progress * 2, 1));
      if (heroVideoWrap) {
        heroVideoWrap.style.transform = `translateY(${progress * 60}px)`;
      }
    }
    window.addEventListener('scroll', onScroll, { passive: true });
  </script>
</body>
</html>