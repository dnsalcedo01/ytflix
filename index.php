<?php
ob_start(); // Start outer output buffer to strictly control AJAX JSON responses

// 1. Session Persistence Fix (Lasts 30 Days)
session_set_cookie_params([
    'lifetime' => 86400 * 30,
    'path' => '/',
    'samesite' => 'Lax'
]);
session_start();

/* ===================================================================================
   YTFLIX - CORE CONFIGURATION & DATABASE SETUP
   =================================================================================== */
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    die("Configuration file missing. Please copy config.example.php to config.php and add your database credentials.");
}

// Initialize Database
try {
    $pdo = new PDO("mysql:host=$db_host;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name`");
    $pdo->exec("USE `$db_name`");

    // Create Tables
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE,
        password VARCHAR(255),
        yt_api_key VARCHAR(255) DEFAULT '',
        tmdb_api_key VARCHAR(255) DEFAULT '',
        yt_playlist_id VARCHAR(255) DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS profiles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        name VARCHAR(50),
        color VARCHAR(20),
        avatar_url VARCHAR(500) DEFAULT '',
        pin VARCHAR(4) DEFAULT '',
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS movies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        yt_video_id VARCHAR(50) UNIQUE,
        raw_title VARCHAR(255),
        clean_title VARCHAR(255),
        description TEXT,
        genre VARCHAR(100),
        release_year VARCHAR(10),
        rating VARCHAR(20) DEFAULT 'No Rating',
        poster_path VARCHAR(255),
        backdrop_path VARCHAR(255),
        actors TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS watchlist (
        id INT AUTO_INCREMENT PRIMARY KEY,
        profile_id INT,
        movie_id INT,
        FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
        FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE,
        UNIQUE(profile_id, movie_id)
    )");

    // Playback Progress Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS playback_progress (
        id INT AUTO_INCREMENT PRIMARY KEY,
        profile_id INT,
        movie_id INT,
        progress_time INT DEFAULT 0,
        duration INT DEFAULT 0,
        last_watched TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
        FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE,
        UNIQUE(profile_id, movie_id)
    )");

    // Shows Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS shows (
        id INT AUTO_INCREMENT PRIMARY KEY,
        yt_playlist_id VARCHAR(255) UNIQUE,
        raw_title VARCHAR(255),
        clean_title VARCHAR(255),
        description TEXT,
        release_year VARCHAR(10),
        rating VARCHAR(20) DEFAULT 'No Rating',
        poster_path VARCHAR(255),
        backdrop_path VARCHAR(255),
        genre VARCHAR(100),
        actors TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Episodes Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS episodes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        show_id INT,
        yt_video_id VARCHAR(50) UNIQUE,
        episode_number INT,
        title VARCHAR(255),
        description TEXT,
        release_year VARCHAR(10),
        thumbnail_path VARCHAR(255),
        duration INT DEFAULT 0,
        FOREIGN KEY (show_id) REFERENCES shows(id) ON DELETE CASCADE
    )");

    // Episode Playback Progress Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS episode_playback_progress (
        id INT AUTO_INCREMENT PRIMARY KEY,
        profile_id INT,
        episode_id INT,
        progress_time INT DEFAULT 0,
        duration INT DEFAULT 0,
        last_watched TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
        FOREIGN KEY (episode_id) REFERENCES episodes(id) ON DELETE CASCADE,
        UNIQUE(profile_id, episode_id)
    )");

    // Safe Schema Migrations
    try { $pdo->exec("ALTER TABLE profiles ADD COLUMN avatar_url VARCHAR(500) DEFAULT '' AFTER color"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE profiles ADD COLUMN pin VARCHAR(4) DEFAULT '' AFTER avatar_url"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE movies ADD COLUMN rating VARCHAR(20) DEFAULT 'No Rating' AFTER release_year"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE movies MODIFY COLUMN rating VARCHAR(20) DEFAULT 'No Rating'"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE playback_progress ADD COLUMN duration INT DEFAULT 0 AFTER progress_time"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE watchlist ADD COLUMN show_id INT NULL DEFAULT NULL AFTER movie_id"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE watchlist MODIFY COLUMN movie_id INT NULL DEFAULT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE watchlist ADD FOREIGN KEY (show_id) REFERENCES shows(id) ON DELETE CASCADE"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE movies ADD COLUMN actors TEXT"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE shows ADD COLUMN actors TEXT"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE episodes ADD COLUMN title VARCHAR(255)"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE episodes ADD COLUMN description TEXT"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE episodes ADD COLUMN release_year VARCHAR(10)"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE episodes ADD COLUMN thumbnail_path VARCHAR(255)"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE episodes ADD COLUMN duration INT DEFAULT 0"); } catch (PDOException $e) {}

    // Insert default admin if no users exist
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    if ($stmt->fetchColumn() == 0) {
        $hash = password_hash('admin', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO users (username, password) VALUES ('admin', '$hash')");
    }

} catch (PDOException $e) {
    die("<h2 style='color:red; font-family:sans-serif;'>Database Connection Failed. Please ensure MySQL is running.</h2> Error: " . $e->getMessage());
}

/* ===================================================================================
   HELPER FUNCTIONS & API LOGIC
   =================================================================================== */
function fetchTMDB($titleInfo, $tmdbKey) {
    $tmdbKey = trim($tmdbKey);
    if(empty($tmdbKey)) return false;
    
    $query = urlencode($titleInfo['title']);
    $baseUrl = "https://api.themoviedb.org/3/search/movie?api_key={$tmdbKey}&include_adult=false";
    $url = $titleInfo['year'] ? $baseUrl . "&query={$query}&year=" . $titleInfo['year'] : $baseUrl . "&query={$query}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $data = json_decode(curl_exec($ch), true);
    
    if (empty($data['results']) && $titleInfo['year']) {
        curl_setopt($ch, CURLOPT_URL, $baseUrl . "&query={$query}");
        $data = json_decode(curl_exec($ch), true);
    }
    if (empty($data['results'])) {
        $words = explode(' ', $titleInfo['title']);
        if (count($words) >= 2) {
            $shortQuery = urlencode($words[0] . ' ' . $words[1]);
            curl_setopt($ch, CURLOPT_URL, $baseUrl . "&query={$shortQuery}");
            $data = json_decode(curl_exec($ch), true);
        }
    }
    curl_close($ch);
    
    if (!empty($data['results'][0])) {
        $movie = $data['results'][0];
        $creditsUrl = "https://api.themoviedb.org/3/movie/{$movie['id']}/credits?api_key={$tmdbKey}";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $creditsUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $credData = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        $actors = [];
        if (!empty($credData['cast'])) {
            foreach (array_slice($credData['cast'], 0, 8) as $actor) {
                $actors[] = [
                    'name' => $actor['name'],
                    'character' => $actor['character'],
                    'profile' => $actor['profile_path'] ? "https://image.tmdb.org/t/p/w185" . $actor['profile_path'] : null
                ];
            }
        }
        
        $detailUrl = "https://api.themoviedb.org/3/movie/{$movie['id']}?api_key={$tmdbKey}&append_to_response=release_dates";
        $ch = curl_init($detailUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $detailData = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        $genres = [];
        if(!empty($detailData['genres'])) {
            foreach($detailData['genres'] as $g) { $genres[] = $g['name']; }
        }

        // Flawless Certification Extraction
        $rating = 'No Rating';
        if (!empty($detailData['release_dates']['results'])) {
            // Priority 1: Direct US Certification
            foreach ($detailData['release_dates']['results'] as $rd) {
                if (isset($rd['iso_3166_1']) && $rd['iso_3166_1'] === 'US') {
                    if (!empty($rd['release_dates'])) {
                        foreach ($rd['release_dates'] as $r) {
                            if (!empty($r['certification'])) {
                                $rating = $r['certification'];
                                break 2;
                            }
                        }
                    }
                }
            }
            // Priority 2: Fallback to ANY valid country certification
            if ($rating === 'No Rating') {
                foreach ($detailData['release_dates']['results'] as $rd) {
                    if (!empty($rd['release_dates'])) {
                        foreach ($rd['release_dates'] as $r) {
                            if (!empty($r['certification'])) {
                                $rating = $r['certification'];
                                break 2;
                            }
                        }
                    }
                }
            }
        }
        
        return [
            'title' => $movie['title'],
            'description' => $movie['overview'],
            'year' => substr($movie['release_date'] ?? '', 0, 4),
            'rating' => $rating,
            'poster_path' => $movie['poster_path'] ? "https://image.tmdb.org/t/p/w500" . $movie['poster_path'] : "",
            'backdrop_path' => $movie['backdrop_path'] ? "https://image.tmdb.org/t/p/original" . $movie['backdrop_path'] : "",
            'actors' => json_encode($actors), 
            'genre' => implode(", ", array_slice($genres, 0, 3)) 
        ];
    }
    return false;
}

function fetchTMDBById($tmdbId, $tmdbKey) {
    $tmdbKey = trim($tmdbKey);
    if(empty($tmdbKey)) return false;
    
    $url = "https://api.themoviedb.org/3/movie/{$tmdbId}?api_key={$tmdbKey}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $movieData = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (isset($movieData['id'])) {
        $credUrl = "https://api.themoviedb.org/3/movie/{$tmdbId}/credits?api_key={$tmdbKey}";
        $ch = curl_init($credUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $credData = json_decode(curl_exec($ch), true);
        curl_close($ch);

        $actors = [];
        if (!empty($credData['cast'])) {
            foreach (array_slice($credData['cast'], 0, 8) as $actor) {
                $actors[] = [
                    'name' => $actor['name'],
                    'character' => $actor['character'],
                    'profile' => $actor['profile_path'] ? "https://image.tmdb.org/t/p/w185" . $actor['profile_path'] : null
                ];
            }
        }

        $detailUrl = "https://api.themoviedb.org/3/movie/{$tmdbId}?api_key={$tmdbKey}&append_to_response=release_dates";
        $ch = curl_init($detailUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $detailData = json_decode(curl_exec($ch), true);
        curl_close($ch);

        $genres = [];
        if(!empty($movieData['genres'])) {
            foreach($movieData['genres'] as $g) { $genres[] = $g['name']; }
        }

        // Flawless Certification Extraction
        $rating = 'No Rating';
        if (!empty($detailData['release_dates']['results'])) {
            foreach ($detailData['release_dates']['results'] as $rd) {
                if (isset($rd['iso_3166_1']) && $rd['iso_3166_1'] === 'US') {
                    if (!empty($rd['release_dates'])) {
                        foreach ($rd['release_dates'] as $r) {
                            if (!empty($r['certification'])) {
                                $rating = $r['certification'];
                                break 2;
                            }
                        }
                    }
                }
            }
            if ($rating === 'No Rating') {
                foreach ($detailData['release_dates']['results'] as $rd) {
                    if (!empty($rd['release_dates'])) {
                        foreach ($rd['release_dates'] as $r) {
                            if (!empty($r['certification'])) {
                                $rating = $r['certification'];
                                break 2;
                            }
                        }
                    }
                }
            }
        }

        return [
            'title' => $movieData['title'],
            'description' => $movieData['overview'],
            'year' => substr($movieData['release_date'] ?? '', 0, 4),
            'rating' => $rating,
            'poster_path' => $movieData['poster_path'] ? "https://image.tmdb.org/t/p/w500" . $movieData['poster_path'] : "",
            'backdrop_path' => $movieData['backdrop_path'] ? "https://image.tmdb.org/t/p/original" . $movieData['backdrop_path'] : "",
            'actors' => json_encode($actors), 
            'genre' => implode(", ", array_slice($genres, 0, 3)) 
        ];
    }
    return false;
}

function handleAvatarUpload($fileArray) {
    if (isset($fileArray) && $fileArray['error'] == UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/avatars/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $fileTmpPath = $fileArray['tmp_name'];
        $fileName = $fileArray['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedfileExtensions = array('jpg', 'gif', 'png', 'jpeg', 'webp');
        if (in_array($fileExtension, $allowedfileExtensions)) {
            $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
            $dest_path = $uploadDir . $newFileName;
            if(move_uploaded_file($fileTmpPath, $dest_path)) {
                return $dest_path;
            }
        }
    }
    return '';
}

/* ===================================================================================
   AUTHENTICATION & AUTO-LOGIN COOKIE LAYER
   =================================================================================== */
if (!isset($_SESSION['user_id']) && isset($_COOKIE['ytflix_user'])) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$_COOKIE['ytflix_user']]);
    if ($stmt->fetch()) {
        $_SESSION['user_id'] = $_COOKIE['ytflix_user'];
        if (isset($_COOKIE['ytflix_profile'])) {
            $_SESSION['profile_id'] = $_COOKIE['ytflix_profile'];
        }
    } else {
        setcookie('ytflix_user', '', time() - 3600, "/");
    }
}

$is_main_profile = false;
$main_profile_id = null;
if (isset($_SESSION['user_id']) && isset($_SESSION['profile_id'])) {
    $mainProfStmt = $pdo->prepare("SELECT id FROM profiles WHERE user_id = ? ORDER BY id ASC LIMIT 1");
    $mainProfStmt->execute([$_SESSION['user_id']]);
    $main_profile_id = $mainProfStmt->fetchColumn();
    if ($main_profile_id == $_SESSION['profile_id']) {
        $is_main_profile = true;
    }
}

/* ===================================================================================
   AJAX ENDPOINTS & POST HANDLERS
   =================================================================================== */
if (isset($_GET['ajax'])) {
    if ($_GET['ajax'] == 'toggle_watchlist' && isset($_SESSION['profile_id'])) {
        header('Content-Type: application/json');
        $movieId = $_POST['movie_id'];
        $profId = $_SESSION['profile_id'];
        $stmt = $pdo->prepare("SELECT id FROM watchlist WHERE profile_id = ? AND movie_id = ?");
        $stmt->execute([$profId, $movieId]);
        if ($stmt->fetch()) {
            $pdo->prepare("DELETE FROM watchlist WHERE profile_id = ? AND movie_id = ?")->execute([$profId, $movieId]);
            echo json_encode(['status' => 'removed']);
        } else {
            $pdo->prepare("INSERT INTO watchlist (profile_id, movie_id) VALUES (?, ?)")->execute([$profId, $movieId]);
            echo json_encode(['status' => 'added']);
        }
        exit;
    }
    
    if ($_GET['ajax'] == 'toggle_show_watchlist' && isset($_SESSION['profile_id'])) {
        header('Content-Type: application/json');
        $showId = $_POST['show_id'];
        $profId = $_SESSION['profile_id'];
        $stmt = $pdo->prepare("SELECT id FROM watchlist WHERE profile_id = ? AND show_id = ?");
        $stmt->execute([$profId, $showId]);
        if ($stmt->fetch()) {
            $pdo->prepare("DELETE FROM watchlist WHERE profile_id = ? AND show_id = ?")->execute([$profId, $showId]);
            echo json_encode(['status' => 'removed']);
        } else {
            $pdo->prepare("INSERT INTO watchlist (profile_id, show_id) VALUES (?, ?)")->execute([$profId, $showId]);
            echo json_encode(['status' => 'added']);
        }
        exit;
    }

    if ($_GET['ajax'] == 'save_episode_progress' && isset($_SESSION['profile_id'])) {
        header('Content-Type: application/json');
        $epId = (int)$_POST['episode_id'];
        $time = (int)$_POST['time'];
        $duration = (int)($_POST['duration'] ?? 0);
        $profId = $_SESSION['profile_id'];
        $stmt = $pdo->prepare("INSERT INTO episode_playback_progress (profile_id, episode_id, progress_time, duration) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE progress_time = ?, duration = ?, last_watched = CURRENT_TIMESTAMP");
        $stmt->execute([$profId, $epId, $time, $duration, $time, $duration]);
        echo json_encode(['status' => 'saved']);
        exit;
    }
    
    if ($_GET['ajax'] == 'save_progress' && isset($_SESSION['profile_id'])) {
        header('Content-Type: application/json');
        $movieId = (int)$_POST['movie_id'];
        $time = (int)$_POST['time'];
        $duration = (int)($_POST['duration'] ?? 0);
        $profId = $_SESSION['profile_id'];
        $stmt = $pdo->prepare("INSERT INTO playback_progress (profile_id, movie_id, progress_time, duration) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE progress_time = ?, duration = ?, last_watched = CURRENT_TIMESTAMP");
        $stmt->execute([$profId, $movieId, $time, $duration, $time, $duration]);
        echo json_encode(['status' => 'saved']);
        exit;
    }
}

if (isset($_POST['login'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$_POST['username']]);
    $user = $stmt->fetch();
    if ($user && password_verify($_POST['password'], $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        setcookie('ytflix_user', $user['id'], time() + (86400 * 30), "/"); // 30 Day Auto-Login
        header("Location: ?p=profiles");
        exit;
    } else {
        $login_error = "Invalid credentials.";
    }
}

if (isset($_POST['login_profile_id'])) {
    $pid = $_POST['login_profile_id'];
    $pin = $_POST['login_pin'] ?? '';
    $stmt = $pdo->prepare("SELECT pin FROM profiles WHERE id = ? AND user_id = ?");
    $stmt->execute([$pid, $_SESSION['user_id']]);
    $profPin = $stmt->fetchColumn();
    if ($profPin !== false && (empty($profPin) || $profPin === $pin)) {
        $_SESSION['profile_id'] = $pid;
        setcookie('ytflix_profile', $pid, time() + (86400 * 30), "/"); // 30 Day Session Lock
        header("Location: ?p=home");
        exit;
    } else {
        $pin_error = "Incorrect Profile PIN.";
    }
}

if (isset($_GET['select_profile'])) {
    $pid = $_GET['select_profile'];
    $stmt = $pdo->prepare("SELECT pin FROM profiles WHERE id = ? AND user_id = ?");
    $stmt->execute([$pid, $_SESSION['user_id']]);
    $profPin = $stmt->fetchColumn();
    if (empty($profPin)) {
        $_SESSION['profile_id'] = $pid;
        setcookie('ytflix_profile', $pid, time() + (86400 * 30), "/");
        header("Location: ?p=home");
        exit;
    } else {
        header("Location: ?p=profiles");
        exit;
    }
}

if (isset($_GET['logout'])) {
    setcookie('ytflix_user', '', time() - 3600, "/");
    setcookie('ytflix_profile', '', time() - 3600, "/");
    session_destroy();
    header("Location: ?p=login");
    exit;
}

if (isset($_POST['create_profile'])) {
    if (!isset($_SESSION['profile_id']) || $is_main_profile) {
        $colors = ['#E50914', '#0071eb', '#00b020', '#ffb000', '#9c27b0', '#e91e63', '#00bcd4', '#3f51b5', '#ff5722', '#795548'];
        $stmtColors = $pdo->prepare("SELECT color FROM profiles WHERE user_id = ?");
        $stmtColors->execute([$_SESSION['user_id']]);
        $usedColors = $stmtColors->fetchAll(PDO::FETCH_COLUMN);
        $availableColors = array_diff($colors, $usedColors);
        $color = empty($availableColors) ? $colors[array_rand($colors)] : $availableColors[array_rand($availableColors)];
        
        $avatarUrl = handleAvatarUpload($_FILES['avatar_file']);
        $stmt = $pdo->prepare("INSERT INTO profiles (user_id, name, color, avatar_url) VALUES (?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $_POST['profile_name'], $color, $avatarUrl]);
        
        if (isset($_POST['admin_redirect'])) header("Location: ?p=admin&tab=account&profile_added=1");
        else header("Location: ?p=profiles");
        exit;
    }
}

if (isset($_POST['rename_profile'])) {
    $newName = trim($_POST['new_profile_name']);
    if ($newName !== '') {
        $targetId = !$is_main_profile ? $_SESSION['profile_id'] : $_POST['target_profile_id'];
        $stmt = $pdo->prepare("UPDATE profiles SET name=? WHERE id=?");
        $stmt->execute([$newName, $targetId]);
    }
    header("Location: ?p=admin&tab=account&profile_updated=1");
    exit;
}

if (isset($_POST['update_profile_pic'])) {
    $avatarUrl = handleAvatarUpload($_FILES['avatar_file']);
    if ($avatarUrl !== '') {
        $targetId = !$is_main_profile ? $_SESSION['profile_id'] : $_POST['target_profile_id'];
        $stmt = $pdo->prepare("UPDATE profiles SET avatar_url=? WHERE id=?");
        $stmt->execute([$avatarUrl, $targetId]);
    }
    header("Location: ?p=admin&tab=account&profile_updated=1");
    exit;
}

if (isset($_POST['delete_profile_pic'])) {
    $targetId = !$is_main_profile ? $_SESSION['profile_id'] : $_POST['target_profile_id'];
    $stmt = $pdo->prepare("UPDATE profiles SET avatar_url='' WHERE id=?");
    $stmt->execute([$targetId]);
    header("Location: ?p=admin&tab=account&profile_updated=1");
    exit;
}

if (isset($_POST['update_pin'])) {
    $targetId = !$is_main_profile ? $_SESSION['profile_id'] : $_POST['target_profile_id'];
    $newPin = trim($_POST['new_pin']);
    if (empty($newPin) || preg_match('/^\d{4}$/', $newPin)) {
        $stmt = $pdo->prepare("UPDATE profiles SET pin=? WHERE id=?");
        $stmt->execute([$newPin, $targetId]);
    }
    header("Location: ?p=admin&tab=account&profile_updated=1");
    exit;
}

if (isset($_POST['delete_profile'])) {
    $targetId = !$is_main_profile ? $_SESSION['profile_id'] : $_POST['target_profile_id'];
    if ($targetId != $main_profile_id) {
        $stmt = $pdo->prepare("DELETE FROM profiles WHERE id=?");
        $stmt->execute([$targetId]);
        if ($targetId == $_SESSION['profile_id']) {
            unset($_SESSION['profile_id']);
            header("Location: ?p=profiles");
            exit;
        }
    }
    header("Location: ?p=admin&tab=account&profile_deleted=1");
    exit;
}

if (isset($_POST['update_password']) && $is_main_profile) {
    $hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password=? WHERE id=?");
    $stmt->execute([$hash, $_SESSION['user_id']]);
    header("Location: ?p=admin&tab=account&pw_saved=1");
    exit;
}

if (isset($_POST['sync_playlist']) && $is_main_profile) {
    set_time_limit(0); 
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $admin = $stmt->fetch();
    
    if ($admin['yt_api_key'] && $admin['yt_playlist_id']) {
        $playlistId = $admin['yt_playlist_id'];
        if (strpos($playlistId, 'list=') !== false) {
            parse_str(parse_url($playlistId, PHP_URL_QUERY), $vars);
            $playlistId = $vars['list'];
        }

        $nextPageToken = '';
        do {
            $ytUrl = "https://www.googleapis.com/youtube/v3/playlistItems?part=snippet&maxResults=50&playlistId={$playlistId}&key={$admin['yt_api_key']}";
            if ($nextPageToken) $ytUrl .= "&pageToken={$nextPageToken}";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $ytUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $ytResp = json_decode(curl_exec($ch), true);
            curl_close($ch);

            if (isset($ytResp['nextPageToken'])) $nextPageToken = $ytResp['nextPageToken'];
            else $nextPageToken = false;

            if (isset($ytResp['items'])) {
                foreach ($ytResp['items'] as $item) {
                    $vidId = $item['snippet']['resourceId']['videoId'];
                    $rawTitle = $item['snippet']['title'];
                    if ($rawTitle == 'Private video' || $rawTitle == 'Deleted video') continue;
                    
                    $check = $pdo->prepare("SELECT id FROM movies WHERE yt_video_id = ?");
                    $check->execute([$vidId]);
                    $existing = $check->fetch(PDO::FETCH_ASSOC);

                    if (!$existing) {
                        $cleanData = ['title' => $rawTitle, 'year' => null];
                        $tmdbData = fetchTMDB($cleanData, $admin['tmdb_api_key']);
                        if ($tmdbData) {
                            $ins = $pdo->prepare("INSERT INTO movies (yt_video_id, raw_title, clean_title, description, genre, release_year, poster_path, backdrop_path, actors) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $ins->execute([$vidId, $rawTitle, $tmdbData['title'], $tmdbData['description'], $tmdbData['genre'], $tmdbData['year'], $tmdbData['poster_path'], $tmdbData['backdrop_path'], $tmdbData['actors']]);
                        } else {
                            $ins = $pdo->prepare("INSERT INTO movies (yt_video_id, raw_title, clean_title, description, poster_path) VALUES (?, ?, ?, ?, ?)");
                            $ins->execute([$vidId, $rawTitle, $rawTitle, "Description not found on TMDB.", $item['snippet']['thumbnails']['high']['url'] ?? '']);
                        }
                    }
                }
            } else break; 
        } while ($nextPageToken);
    }
    header("Location: ?p=admin&tab=library&synced=1");
    exit;
}

if (isset($_POST['update_settings']) && $is_main_profile) {
    $stmt = $pdo->prepare("UPDATE users SET yt_api_key=?, tmdb_api_key=?, yt_playlist_id=? WHERE id=?");
    $stmt->execute([$_POST['yt_api'], $_POST['tmdb_api'], $_POST['yt_playlist'], $_SESSION['user_id']]);
    header("Location: ?p=admin&tab=account&saved=1");
    exit;
}

if (isset($_POST['edit_movie']) && $is_main_profile) {
    $movieId = $_POST['movie_id'];
    $tmdbForceLink = trim($_POST['tmdb_force_link'] ?? '');

    if (!empty($tmdbForceLink)) {
        if (preg_match('/movie\/(\d+)/', $tmdbForceLink, $matches) || preg_match('/^(\d+)\/?$/', $tmdbForceLink, $matches)) {
            $tmdbId = $matches[1];
            $adminStmt = $pdo->prepare("SELECT tmdb_api_key FROM users LIMIT 1");
            $adminStmt->execute();
            $tmdbKey = $adminStmt->fetchColumn();

            if ($tmdbKey) {
                $tmdbData = fetchTMDBById($tmdbId, $tmdbKey);
                if ($tmdbData) {
                    $upd = $pdo->prepare("UPDATE movies SET clean_title=?, description=?, release_year=?, poster_path=?, backdrop_path=?, actors=?, genre=? WHERE id=?");
                    $upd->execute([$tmdbData['title'], $tmdbData['description'], $tmdbData['year'], $tmdbData['poster_path'], $tmdbData['backdrop_path'], $tmdbData['actors'], $tmdbData['genre'], $movieId]);
                    header("Location: ?p=admin&tab=library&edited=1");
                    exit;
                }
            }
        }
    }

    $stmt = $pdo->prepare("UPDATE movies SET clean_title=?, description=?, release_year=?, backdrop_path=? WHERE id=?");
    $stmt->execute([$_POST['m_title'], $_POST['m_desc'], $_POST['m_year'], $_POST['m_backdrop'], $movieId]);
    header("Location: ?p=admin&tab=library&edited=1");
    exit;
}

if (isset($_POST['delete_movie']) && $is_main_profile) {
    $stmt = $pdo->prepare("DELETE FROM movies WHERE id = ?");
    $stmt->execute([$_POST['movie_id']]);
    header("Location: ?p=admin&tab=library&deleted=1");
    exit;
}

/* ===================================================================================
   SHOWS MANAGEMENT BACKEND
   =================================================================================== */
if (isset($_POST['add_show']) && $is_main_profile) {
    $ytPlaylistUrl = trim($_POST['yt_playlist']);
    $tmdbIdUrl = trim($_POST['tmdb_id'] ?? '');

    $playlistId = $ytPlaylistUrl;
    if (strpos($playlistId, 'list=') !== false) {
        parse_str(parse_url($playlistId, PHP_URL_QUERY), $vars);
        if (isset($vars['list'])) $playlistId = $vars['list'];
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $admin = $stmt->fetch();

    $tmdbId = null;
    if (!empty($tmdbIdUrl)) {
        if (preg_match('/tv\/(\d+)/', $tmdbIdUrl, $matches) || preg_match('/^(\d+)\/?$/', $tmdbIdUrl, $matches)) {
            $tmdbId = $matches[1];
        }
    }

    $rawTitle = "Show - " . $playlistId;
    $cleanTitle = $rawTitle;
    $desc = "";
    $year = "";
    $poster = "";
    $backdrop = "";
    $actors = "";
    $genre = "";
    
    if ($admin['yt_api_key']) {
        $ytUrl = "https://www.googleapis.com/youtube/v3/playlists?part=snippet&id={$playlistId}&key={$admin['yt_api_key']}";
        $ch = curl_init($ytUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $ytResp = json_decode(curl_exec($ch), true);
        curl_close($ch);
        if (!empty($ytResp['items'][0])) {
            $rawTitle = $ytResp['items'][0]['snippet']['title'];
            $cleanTitle = $rawTitle;
            $desc = $ytResp['items'][0]['snippet']['description'];
            $poster = $ytResp['items'][0]['snippet']['thumbnails']['high']['url'] ?? '';
        }
    }

    if ($tmdbId && $admin['tmdb_api_key']) {
        $tmdbKey = trim($admin['tmdb_api_key']);
        $url = "https://api.themoviedb.org/3/tv/{$tmdbId}?api_key={$tmdbKey}";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $showData = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($showData['id'])) {
            $credUrl = "https://api.themoviedb.org/3/tv/{$tmdbId}/credits?api_key={$tmdbKey}";
            $ch = curl_init($credUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $credData = json_decode(curl_exec($ch), true);
            curl_close($ch);

            $actArr = [];
            if (!empty($credData['cast'])) {
                foreach (array_slice($credData['cast'], 0, 8) as $actor) {
                    $actArr[] = [
                        'name' => $actor['name'],
                        'character' => $actor['character'],
                        'profile' => $actor['profile_path'] ? "https://image.tmdb.org/t/p/w185" . $actor['profile_path'] : null
                    ];
                }
            }
            
            $genres = [];
            if(!empty($showData['genres'])) {
                foreach($showData['genres'] as $g) { $genres[] = $g['name']; }
            }

            $cleanTitle = $showData['name'];
            $desc = $showData['overview'];
            $year = substr($showData['first_air_date'] ?? '', 0, 4);
            $poster = $showData['poster_path'] ? "https://image.tmdb.org/t/p/w500" . $showData['poster_path'] : $poster;
            $backdrop = $showData['backdrop_path'] ? "https://image.tmdb.org/t/p/original" . $showData['backdrop_path'] : "";
            $actors = json_encode($actArr);
            $genre = implode(", ", array_slice($genres, 0, 3));
        }
    }

    $ins = $pdo->prepare("INSERT INTO shows (yt_playlist_id, raw_title, clean_title, description, release_year, poster_path, backdrop_path, genre, actors) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE raw_title=VALUES(raw_title)");
    $ins->execute([$playlistId, $rawTitle, $cleanTitle, $desc, $year, $poster, $backdrop, $genre, $actors]);
    
    header("Location: ?p=admin&tab=shows&show_added=1");
    exit;
}

if (isset($_POST['sync_show_episodes']) && $is_main_profile) {
    set_time_limit(0);
    $showId = (int)$_POST['show_id'];
    $stmt = $pdo->prepare("SELECT * FROM shows WHERE id = ?");
    $stmt->execute([$showId]);
    $show = $stmt->fetch();
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $admin = $stmt->fetch();

    if ($show && $admin['yt_api_key']) {
        $playlistId = $show['yt_playlist_id'];
        $nextPageToken = '';
        $epCount = 0;
        
        $epNumStmt = $pdo->prepare("SELECT MAX(episode_number) FROM episodes WHERE show_id = ?");
        $epNumStmt->execute([$showId]);
        $maxEp = $epNumStmt->fetchColumn();
        if (!$maxEp) $maxEp = 0;

        do {
            $ytUrl = "https://www.googleapis.com/youtube/v3/playlistItems?part=snippet&maxResults=50&playlistId={$playlistId}&key={$admin['yt_api_key']}";
            if ($nextPageToken) $ytUrl .= "&pageToken={$nextPageToken}";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $ytUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $ytResp = json_decode(curl_exec($ch), true);
            curl_close($ch);

            if (isset($ytResp['nextPageToken'])) $nextPageToken = $ytResp['nextPageToken'];
            else $nextPageToken = false;

            if (isset($ytResp['items'])) {
                foreach ($ytResp['items'] as $item) {
                    $vidId = $item['snippet']['resourceId']['videoId'];
                    $rawTitle = $item['snippet']['title'];
                    if ($rawTitle == 'Private video' || $rawTitle == 'Deleted video') continue;
                    
                    $check = $pdo->prepare("SELECT id FROM episodes WHERE show_id = ? AND yt_video_id = ?");
                    $check->execute([$showId, $vidId]);
                    if (!$check->fetch()) {
                        $maxEp++;
                        $thumb = $item['snippet']['thumbnails']['high']['url'] ?? '';
                        $desc = $item['snippet']['description'];
                        
                        $ins = $pdo->prepare("INSERT INTO episodes (show_id, yt_video_id, episode_number, title, description, thumbnail_path) VALUES (?, ?, ?, ?, ?, ?)");
                        $ins->execute([$showId, $vidId, $maxEp, $rawTitle, $desc, $thumb]);
                        $epCount++;
                    }
                }
            } else break; 
        } while ($nextPageToken);
        
        header("Location: ?p=admin&tab=shows&episodes_synced=" . $epCount);
        exit;
    }
    header("Location: ?p=admin&tab=shows&sync_error=Missing+API+Key");
    exit;
}

if (isset($_POST['edit_show']) && $is_main_profile) {
    $showId = $_POST['show_id'];
    $stmt = $pdo->prepare("UPDATE shows SET clean_title=?, description=?, release_year=?, poster_path=?, backdrop_path=? WHERE id=?");
    $stmt->execute([$_POST['s_title'], $_POST['s_desc'], $_POST['s_year'], $_POST['s_poster'], $_POST['s_backdrop'], $showId]);
    header("Location: ?p=admin&tab=shows&show_edited=1");
    exit;
}

if (isset($_POST['delete_show']) && $is_main_profile) {
    $stmt = $pdo->prepare("DELETE FROM shows WHERE id = ?");
    $stmt->execute([$_POST['show_id']]);
    header("Location: ?p=admin&tab=shows&show_deleted=1");
    exit;
}

if (isset($_POST['edit_episode']) && $is_main_profile) {
    $epId = $_POST['episode_id'];
    $showId = $_POST['show_id'];
    $stmt = $pdo->prepare("UPDATE episodes SET title=?, description=?, episode_number=?, thumbnail_path=? WHERE id=?");
    $stmt->execute([$_POST['e_title'], $_POST['e_desc'], $_POST['e_number'], $_POST['e_thumbnail'], $epId]);
    header("Location: ?p=admin&tab=episodes&show_id=" . $showId . "&ep_edited=1");
    exit;
}

/* ===================================================================================
   ROUTING
   =================================================================================== */
$page = isset($_GET['p']) ? $_GET['p'] : 'home';
if ($page == 'profiles') unset($_SESSION['profile_id']);

if (!isset($_SESSION['user_id']) && $page != 'login') {
    header("Location: ?p=login");
    exit;
}
if (isset($_SESSION['user_id']) && !isset($_SESSION['profile_id']) && $page != 'profiles' && $page != 'login') {
    header("Location: ?p=profiles");
    exit;
}

$currentUser = null;
$currentProfile = null;
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $currentUser = $stmt->fetch();
}
if (isset($_SESSION['profile_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM profiles WHERE id = ?");
    $stmt->execute([$_SESSION['profile_id']]);
    $currentProfile = $stmt->fetch();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YTFLIX</title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="manifest" href="manifest.json" crossorigin="use-credentials">
    <meta name="theme-color" content="#E50914">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ==================== GLOBAL STYLES ==================== */
        :root {
            --bg: #141414;
            --primary: #E50914;
            --text: #ffffff;
            --gray: #808080;
            --dark-gray: #181818;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            background-color: var(--bg);
            color: var(--text);
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }
        a { text-decoration: none; color: inherit; }
        button { cursor: pointer; border: none; outline: none; font-family: inherit; }
        h1, h2, h3 { font-weight: 500; }

        .logo-img { max-height: 100%; object-fit: contain; }

        body.is-keyboard .tv-focusable:focus-visible {
            outline: 4px solid white !important;
            outline-offset: 2px;
            transform: scale(1.05);
            z-index: 10;
            transition: all 0.2s ease;
            box-shadow: 0 0 20px rgba(255,255,255,0.4);
        }

        /* ==================== NAVBAR ==================== */
        nav {
            position: fixed; top: 0; width: 100%; padding: 15px 4%;
            display: flex; justify-content: space-between; align-items: center;
            z-index: 100; transition: transform 0.3s ease, background 0.3s ease;
            background: linear-gradient(to bottom, rgba(0,0,0,0.8) 0%, transparent 100%);
        }
        nav.scrolled { transform: translateY(-100%); background: transparent; }
        .nav-links { display: flex; gap: 25px; align-items: center; }
        .nav-left { flex: 1; justify-content: flex-start; }
        
        /* Updated Nav Center specific for Pill Shapes on Focus */
        .nav-center {
            display: flex; gap: 5px; align-items: center; font-weight: 500; font-size: 1.05rem;
            flex: 2; justify-content: center;
        }
        .nav-center a { 
            color: #ccc; text-decoration: none; font-weight: 500; font-size: 1rem; 
            transition: color 0.2s, transform 0.2s; display: flex; align-items: center; gap: 8px;
            padding: 6px 16px; border-radius: 30px; border: 2px solid transparent;
        }
        .nav-center a i { display: inline-block; }
        .nav-center a:hover, .nav-center a:focus { color: #fff; transform: scale(1.05); background: rgba(255,255,255,0.1); border-color: transparent; }
        .nav-center a.active { outline: none !important; color: white; border-color: transparent; background: rgba(255,255,255,0.2); }
        body.is-keyboard .nav-center a.tv-focusable:focus-visible { outline: none !important; color: white; border-color: white; background: rgba(255,255,255,0.1); transform: scale(1.05); }

        /* Globally hide icons for links that have text labels (except Search) */
        .nav-center a span { display: inline-block; }
        .nav-center a:has(span) i { display: none; }
        
        .nav-right { flex: 1; justify-content: flex-end; }
        
        .profile-icon {
            width: 40px; height: 40px; border-radius: 4px; display: inline-flex;
            align-items: center; justify-content: center; font-weight: bold; font-size: 1.2rem; cursor: pointer;
            background-size: cover; background-position: center;
        }

        /* ==================== PROFILES ==================== */
        .auth-container {
            height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center;
            background: linear-gradient(rgba(0,0,0,0.6), rgba(0,0,0,0.6)), url('https://assets.nflxext.com/ffe/siteui/vlv3/93da5c27-be66-427c-8b72-5cb39d275279/94eb5ad7-10d8-4cca-bf45-ac52e0a052c0/US-en-20240226-popsignuptwoweeks-perspective_alpha_website_large.jpg');
            background-size: cover;
        }
        .auth-box {
            background: rgba(0,0,0,0.75); padding: 60px 68px 40px; border-radius: 4px;
            width: 100%; max-width: 450px;
        }
        .auth-box h1 { margin-bottom: 28px; font-size: 2rem; }
        .auth-box input[type="text"], .auth-box input[type="password"] {
            width: 100%; padding: 16px 20px; margin-bottom: 16px;
            background: #333; color: white; border: none; border-radius: 4px;
        }
        .auth-box button {
            width: 100%; padding: 16px; background: var(--primary); color: white;
            font-size: 1rem; font-weight: bold; border-radius: 4px; margin-top: 24px;
        }

        .profiles-wrapper { text-align: center; margin-top: 15vh; }
        .profiles-wrapper h2 { font-size: clamp(28px, 3.5vw, 60px); margin-bottom: 1.5em; font-weight:normal; }
        .profiles-list { display: flex; justify-content: center; gap: clamp(15px, 2vw, 40px); flex-wrap: wrap; padding: 0 5%; }
        .profile-card { display: flex; flex-direction: column; align-items: center; gap: 10px; cursor: pointer; padding: 10px; border-radius: 8px; position: relative; }
        .profile-avatar {
            width: clamp(84px, 10vw, 200px); height: clamp(84px, 10vw, 200px);
            border-radius: 4px; display: flex; align-items: center; justify-content: center;
            font-size: 3rem; font-weight: bold; border: 2px solid transparent;
            transition: border 0.2s; background-size: cover; background-position: center;
        }
        .profile-card:hover .profile-avatar, .profile-card:focus .profile-avatar { border-color: white; }
        .profile-card span { color: var(--gray); font-size: clamp(14px, 1.5vw, 20px); }
        .profile-card:hover span, .profile-card:focus span { color: white; }
        
        .profile-lock {
            position: absolute; 
            top: 8px; 
            right: 8px; 
            background: rgba(0, 0, 0, 0.75); 
            border-radius: 50%; 
            width: 32px; 
            height: 32px; 
            min-width: 32px;
            min-height: 32px;
            display: flex; 
            align-items: center; 
            justify-content: center; 
            box-shadow: 0 2px 6px rgba(0,0,0,0.6);
            border: 2px solid rgba(255, 255, 255, 0.3);
            box-sizing: border-box;
        }
        .profile-lock i {
            color: white; 
            font-size: 14px;
            margin: 0;
            padding: 0;
        }

        .pin-box-container { display: flex; justify-content: center; gap: 12px; margin-bottom: 30px; }
        .pin-box {
            width: 65px; height: 65px; background: transparent !important; 
            border: 4px solid #444 !important; border-radius: 2px;
            color: white !important; font-size: 2.5rem; font-weight: bold;
            text-align: center; outline: none; padding: 0 !important; 
            transition: border-color 0.2s, transform 0.2s;
            -webkit-appearance: none; box-shadow: none;
        }
        .pin-box:focus { border-color: white !important; transform: scale(1.05); }

        /* ==================== HERO SECTION ==================== */
        .hero-wrapper { padding: 85px 4% 30px 4%; }
        .hero-carousel { 
            position: relative; width: 100%; overflow: hidden; background: #111; 
            aspect-ratio: 16 / 9;
            min-height: 55vh; min-height: 55svh; 
            max-height: 80vh; max-height: min(80svh, 800px);
            border-radius: 12px; border: 1px solid rgba(255,255,255,0.1);
            box-shadow: 0 10px 40px rgba(0,0,0,0.8);
        }
        .hero-slide {
            position: absolute; inset: 0; opacity: 0; transition: opacity 1.5s ease-in-out;
            background-size: cover; background-position: center top; display: flex; flex-direction: column; justify-content: flex-end; 
            padding: 0 3% 3% 3%;
            z-index: 1; pointer-events: none;
        }
        .hero-slide.active { opacity: 1; z-index: 2; pointer-events: auto; }
        .hero-slide::after {
            content: ''; position: absolute; inset: 0;
            background: linear-gradient(to top, rgba(20,20,20,0.95) 0%, transparent 40%),
                        linear-gradient(to right, rgba(20,20,20,0.9) 0%, transparent 60%);
            z-index: 1; pointer-events: none;
        }
        .hero-content { position: relative; z-index: 3; width: 45%; min-width: 300px; }
        .hero-title { font-size: clamp(32px, 3.5vw, 64px); font-weight: bold; margin-bottom: 0.5rem; text-shadow: 2px 2px 4px rgba(0,0,0,0.8); line-height: 1.1; }
        .hero-desc { font-size: clamp(14px, 1.1vw, 20px); margin-bottom: 1.5rem; text-shadow: 1px 1px 2px rgba(0,0,0,0.8); display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; color: #e5e5e5; }
        .btn-group { display: flex; gap: 1rem; }
        .btn { padding: 10px 26px; border-radius: 30px; font-size: 1.1rem; font-weight: bold; display: flex; align-items: center; gap: 8px; transition: 0.2s; }
        .btn-play { background: white; color: black; }
        .btn-play:hover, .btn-play:focus { background: rgba(255,255,255,0.75); transform: scale(1.05); }
        .btn-more { background: rgba(109, 109, 110, 0.4); color: white; backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.2); }
        .btn-more:hover, .btn-more:focus { background: rgba(109, 109, 110, 0.7); transform: scale(1.05); }

        /* ==================== CAROUSELS ==================== */
        .slider-wrapper { margin: 15px 0 25px 0; }
        .slider-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5em; padding: 0 4%; }
        .slider-title { font-size: clamp(18px, 2vw, 24px); font-weight: bold; margin-bottom: 0; }
        .slider-controls { display: flex; gap: 10px; padding-right: 4%; }
        .slider-btn { background: rgba(0, 0, 0, 0.5); color: white; border: 2px solid #333; border-radius: 50%; width: 35px; height: 35px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; cursor: pointer; transition: 0.2s; }
        .slider-btn:hover, .slider-btn:focus { background: rgba(255, 255, 255, 0.2); border-color: white; }
        /* ==================== SLIDER & MOVIE CARDS ==================== */
        .slider { 
            --slider-h: max(23vw - 15px, 195px);
            display: flex; overflow-x: auto; overflow-y: hidden; scroll-behavior: smooth; 
            padding: 15px 4% 65px 4%; scrollbar-width: none; align-items: flex-start;
        }
        .slider::-webkit-scrollbar { display: none; }
        
        .slider-item {
            flex-shrink: 0; position: relative;
            cursor: pointer; outline: none; display: flex; flex-direction: column; border-radius: 6px;
            width: calc( var(--slider-h) * 0.66666 + 10px ); 
            padding: 0 5px; 
            transition: width 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }
        
        body.is-keyboard .slider-item.tv-focusable:focus-visible { 
            outline: none !important; transform: none !important; box-shadow: none !important; z-index: 10;
            width: calc( var(--slider-h) * 1.77777 + 10px ); 
        }
        
        .slider-img-box {
            position: relative; 
            height: var(--slider-h); 
            width: 100%;
            border-radius: 6px; overflow: hidden; background: #222;
            box-shadow: 0 4px 10px rgba(0,0,0,0.5);
            outline: 2px solid transparent; 
            transition: outline 0.3s ease, box-shadow 0.3s ease;
        }
        
        body.is-keyboard .slider-item.tv-focusable:focus-visible .slider-img-box { 
            outline: 4px solid white; outline-offset: 2px; box-shadow: 0 0 20px rgba(255,255,255,0.4);
        }

        .slider-poster, .slider-backdrop {
            position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: center; transition: opacity 0.3s ease;
        }
        .slider-poster { opacity: 1; }
        .slider-backdrop { opacity: 0; }

        body.is-keyboard .slider-item.tv-focusable:focus-visible .slider-poster { opacity: 0; }
        body.is-keyboard .slider-item.tv-focusable:focus-visible .slider-backdrop { opacity: 1; }

        .slider-img-overlay { position: absolute; inset: 0; background: linear-gradient(to top, rgba(0,0,0,0.9) 0%, transparent 60%); display: flex; align-items: flex-end; justify-content: flex-start; padding: 12px; opacity: 0; transition: opacity 0.3s ease; z-index: 2; pointer-events: none;}
        body.is-keyboard .slider-item.tv-focusable:focus-visible .slider-img-overlay { opacity: 1; }
        
        .slider-img-title { font-weight: 800; font-size: clamp(1rem, 1.5vw, 1.4rem); color: white; line-height: 1.1; text-shadow: 2px 2px 4px rgba(0,0,0,0.8); }

        .slider-resume-btn {
            background: white; color: black; padding: 6px 14px; border-radius: 30px; font-weight: bold; font-size: 0.85rem; margin-bottom: 0; border: none; display: flex; align-items: center; gap: 6px; 
            z-index: 4;
        }

        .slider-progress { position: absolute; bottom: 0; left: 0; width: 100%; height: 4px; background: rgba(255,255,255,0.3); z-index: 3; }
        .slider-progress-fill { height: 100%; background: #E50914; border-radius: 0 2px 2px 0; }

        .slider-info { 
            position: absolute; top: 100%; left: 0;
            display: flex; flex-direction: column; gap: 4px; 
            padding: 8px 4px 0 4px;
            opacity: 0; pointer-events: none; width: 100%;
            transition: opacity 0.3s ease;
        }
        body.is-keyboard .slider-item.tv-focusable:focus-visible .slider-info { 
            opacity: 1;
        }
        
        .slider-meta { font-size: 0.85rem; color: #ccc; font-weight: 600; display:flex; align-items:center; gap:6px; flex-wrap:wrap;}
        .slider-desc { font-size: 0.8rem; color: #999; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.4; }


        
        .cw-info { 
            position: absolute; top: 100%; left: 0;
            display: flex; flex-direction: column; gap: 4px; padding: 8px 4px 0 4px; 
            opacity: 0; pointer-events: none; width: 100%; 
            transition: opacity 0.3s ease;
        }
        body.is-keyboard .slider-item.tv-focusable:focus-visible .cw-info { 
            opacity: 1;
        }
        .cw-title { font-weight: bold; font-size: 1rem; color: white; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; width: 100%; }
        .cw-meta { font-size: 0.9rem; color: #aaa; font-weight: 500; }

        /* Hover expansion disabled globally */

        /* ==================== SEARCH PAGE ==================== */
        .search-page {
            padding: 85px 4% 40px 4%;
            display: flex; gap: 30px; min-height: 100vh;
        }
        .search-left {
            flex: 0 0 260px; display: flex; flex-direction: column; gap: 20px;
            position: sticky; top: 85px; align-self: flex-start;
        }
        .search-right {
            flex: 1; min-width: 0;
        }
        .search-right h2 {
            font-size: clamp(20px, 2.5vw, 28px); font-weight: bold; margin-bottom: 20px;
        }

        .search-input-box {
            width: 100%; padding: 12px 16px; background: #333; border: 2px solid #555;
            color: white; font-size: 1.1rem; border-radius: 6px; outline: none;
            font-family: inherit; transition: border-color 0.2s;
        }
        .search-input-box:focus { border-color: white; }
        .search-input-box::placeholder { color: #888; }

        .vk-grid {
            display: grid; grid-template-columns: repeat(6, 1fr); gap: 4px;
            background: #2a2a2a; padding: 8px; border-radius: 8px;
        }
        .vk-key {
            background: #3a3a3a; color: #e0e0e0; border: 2px solid transparent;
            border-radius: 4px; padding: 10px 0; font-size: 1rem; font-weight: 500;
            text-align: center; cursor: pointer; transition: 0.15s;
            font-family: inherit; display: flex; align-items: center; justify-content: center;
        }
        .vk-key:hover { background: #555; color: white; }
        body.is-keyboard .vk-key.tv-focusable:focus-visible {
            outline: none !important; background: #666; color: white;
            border-color: white; transform: scale(1.05); box-shadow: 0 0 10px rgba(255,255,255,0.3);
        }
        .vk-key.vk-wide { grid-column: span 3; }
        .vk-key.vk-action { background: #4a4a4a; }

        .genre-list {
            display: flex; flex-direction: column; gap: 2px;
        }
        .genre-item {
            padding: 10px 14px; color: #bbb; font-size: 0.95rem; font-weight: 500;
            cursor: pointer; border-radius: 4px; transition: 0.15s;
            border: 2px solid transparent; background: transparent;
            text-align: left; font-family: inherit;
        }
        .genre-item:hover { color: white; background: rgba(255,255,255,0.08); }
        body.is-keyboard .genre-item.tv-focusable:focus-visible {
            outline: none !important; color: white; border-color: white;
            background: rgba(255,255,255,0.1); transform: none; box-shadow: none;
        }
        .genre-item.active { color: white; font-weight: bold; }

        .search-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 15px;
        }
        .search-card {
            position: relative; border-radius: 6px; overflow: hidden; cursor: pointer;
            aspect-ratio: 2/3; background: #222; transition: 0.2s;
            border: 2px solid transparent;
        }
        .search-card img {
            width: 100%; height: 100%; object-fit: cover;
        }
        .search-card-overlay {
            position: absolute; bottom: 0; left: 0; right: 0;
            padding: 10px; background: linear-gradient(to top, rgba(0,0,0,0.9) 0%, transparent 100%);
            display: flex; flex-direction: column; justify-content: flex-end;
        }
        .search-card-title {
            font-weight: bold; font-size: 0.85rem; color: white;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        body.is-keyboard .search-card.tv-focusable:focus-visible {
            border-color: white;
            transform: scale(1.04); z-index: 5;
            box-shadow: 0 0 15px rgba(255,255,255,0.3);
        }
        .search-no-results {
            color: #888; font-size: 1.1rem; padding: 40px 0; text-align: center;
        }

        @media (max-width: 768px) {
            .search-page { flex-direction: column; padding-top: 120px; gap: 20px; }
            .search-left { flex: initial; position: static; width: 100%; }
            .vk-grid { grid-template-columns: repeat(6, 1fr); }
            .search-grid { grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); }
        }

        /* ==================== MODAL ==================== */
        .modal-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.8); z-index: 1000;
            display: none; align-items: center; justify-content: center;
            padding: 20px; 
        }
        /* ==================== DEDICATED MOVIE PAGE ==================== */
        .movie-page-container {
            min-height: 100vh;
            background: var(--bg);
            position: relative;
            padding-bottom: 50px;
        }
        .movie-hero {
            width: 100%;
            height: 60vh;
            min-height: 400px;
            background-size: cover;
            background-position: center top;
            position: relative;
        }
        .movie-hero-gradient {
            position: absolute;
            inset: 0;
            background: linear-gradient(to top, var(--bg) 0%, transparent 80%),
                        linear-gradient(to right, rgba(20,20,20,0.8) 0%, transparent 60%);
        }
        .movie-content {
            position: relative;
            z-index: 2;
            margin-top: -15vh;
            padding: 0 5%;
            display: flex;
            gap: 50px;
            flex-wrap: wrap;
        }
        .movie-info-left {
            flex: 1;
            min-width: 300px;
            max-width: 800px;
        }
        .movie-title {
            font-size: clamp(36px, 5vw, 64px);
            font-weight: bold;
            line-height: 1.1;
            margin-bottom: 20px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.8);
        }
        .movie-meta {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 30px;
            font-size: 1.1rem;
            color: #ccc;
            font-weight: 500;
        }
        .movie-genre {
            color: #fff;
        }
        .movie-actions {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
        }
        .movie-actions .btn-play {
            font-size: 1.2rem;
            padding: 12px 35px;
        }
        .btn-icon {
            background: rgba(40,40,40,0.8);
            border: 2px solid rgba(255,255,255,0.3);
            color: white;
            border-radius: 50%;
            width: 55px;
            height: 55px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            transition: all 0.2s ease;
            backdrop-filter: blur(10px);
        }
        .btn-icon:hover, .btn-icon:focus {
            background: white;
            color: black;
            border-color: white;
            transform: scale(1.1);
        }
        .movie-desc {
            font-size: 1.15rem;
            line-height: 1.6;
            color: #ddd;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.8);
            outline: none;
            border-radius: 6px;
            padding: 5px;
        }
        .movie-desc:focus {
            outline: 2px solid rgba(255,255,255,0.5);
            background: rgba(255,255,255,0.05);
        }
        
        .movie-info-right {
            flex: 0 0 350px;
            margin-top: 30px;
        }
        .cast-header {
            font-size: 1.3rem;
            color: #888;
            margin-bottom: 20px;
        }
        .cast-grid {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .cast-card {
            display: flex;
            align-items: center;
            gap: 15px;
            background: rgba(40,40,40,0.4);
            padding: 10px;
            border-radius: 8px;
            transition: all 0.2s ease;
            outline: none;
            border: 1px solid transparent;
        }
        .cast-card:hover, body.is-keyboard .cast-card.tv-focusable:focus-visible {
            background: rgba(255,255,255,0.1);
            border-color: rgba(255,255,255,0.3);
            transform: scale(1.02);
            z-index: 5;
            box-shadow: none;
        }
        .cast-img {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            background: #222;
        }
        .cast-details {
            display: flex;
            flex-direction: column;
        }
        .cast-name {
            font-weight: bold;
            color: white;
            font-size: 1rem;
        }
        .cast-char {
            color: #aaa;
            font-size: 0.85rem;
        }

        /* ==================== PLAYER ==================== */
        .player-container {
            position: fixed; inset: 0; background: black; z-index: 2000; display: none;
        }
        #yt-iframe-wrapper { position: relative; width: 100%; height: 100%; pointer-events: none; overflow: hidden; } 
        #ytplayer { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }

        .player-controls {
            position: absolute; inset: 0; display: flex; flex-direction: column; justify-content: space-between;
            padding: 30px; background: linear-gradient(to bottom, rgba(0,0,0,0.6) 0%, transparent 20%, transparent 80%, rgba(0,0,0,0.8) 100%);
            opacity: 0; transition: opacity 0.3s; pointer-events: none;
        }
        .player-container.active .player-controls { opacity: 1; pointer-events: auto; }
        .player-header { display: flex; align-items: center; gap: 20px; font-size: 1.5rem; width: 100%; }
        .player-bottom { display: flex; flex-direction: column; gap: 10px; }
        .progress-bar { width: 100%; height: 5px; background: rgba(255,255,255,0.3); cursor: pointer; position: relative; pointer-events: auto; }
        .progress-filled { height: 100%; background: var(--primary); width: 0%; pointer-events: none; transition: width 0.2s linear; }
        .controls-row { display: flex; align-items: center; gap: 15px; font-size: 1.5rem; }
        .player-btn { background: transparent; color: white; display: flex; justify-content: center; align-items: center; padding: 8px 12px; transition: 0.2s; border-radius: 4px; cursor: pointer; border: none; }
        .player-btn:hover, .player-btn:focus { background: rgba(255,255,255,0.2); }
        .cc-active { color: #E50914 !important; border-bottom: 2px solid #E50914; }

        /* ==================== ADMIN PANEL ==================== */
        .admin-panel { padding: 40px 4%; max-width: 1200px; margin: auto; }
        .admin-nav-tabs { display: flex; gap: 20px; margin-bottom: 30px; border-bottom: 1px solid #333; padding-bottom: 10px; }
        .admin-tab { color: var(--gray); font-size: 1.2rem; padding: 10px 15px; cursor: pointer; font-weight: bold; transition: color 0.3s; }
        .admin-tab.active { color: white; border-bottom: 3px solid var(--primary); }
        .admin-card { background: #222; padding: 30px; border-radius: 8px; margin-bottom: 30px; }
        .admin-form label { display: block; margin-bottom: 8px; color: var(--gray); font-weight:bold; margin-top:15px; }
        .admin-form input, .admin-form textarea, .admin-form select { width: 100%; padding: 12px; margin-bottom: 5px; background: #333; border: none; color: white; border-radius: 4px; }
        .btn-primary { background: var(--primary); color: white; padding: 12px 24px; border-radius: 4px; font-weight: bold; }
        .btn-success { background: #28a745; color: white; padding: 12px 24px; border-radius: 4px; font-weight: bold; }
        #adminSearch { width: 100%; padding: 12px; margin-bottom: 20px; background: #333; border: 1px solid #555; color: white; border-radius: 4px; font-size:1.1rem; }

        .movies-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 20px; margin-top: 20px; }
        .movie-grid-item { background: #333; border-radius: 6px; overflow: hidden; display: flex; flex-direction: column; }
        .movie-grid-item img { width: 100%; aspect-ratio: 2/3; object-fit: cover; }
        .movie-grid-info { padding: 12px; display: flex; flex-direction: column; flex-grow: 1; }
        .movie-grid-title { font-size: 0.9rem; font-weight: bold; margin-bottom: 5px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .movie-grid-year { color: #aaa; font-size: 0.8rem; margin-bottom: 15px; }
        .movie-grid-actions { margin-top: auto; display: flex; gap: 10px; }
        .action-btn { flex: 1; display: flex; justify-content: center; align-items: center; padding: 8px; border-radius: 4px; cursor: pointer; color: white; transition: 0.2s; border: none; }
        .edit-btn { background: #0071eb; }
        .del-btn { background: #E50914; }
        .action-btn:hover, .action-btn:focus { filter: brightness(1.2); }

        /* Media Queries */
        @media (max-width: 768px) {
            .slider-controls { display: none; }
            nav { flex-wrap: nowrap; padding: 15px 4% 10px 4% !important; justify-content: space-between; gap: 8px; align-items: center; }
            .nav-left { flex: 0 0 auto; gap: 10px !important; }
            .nav-right { flex: 0 0 auto; }
            .nav-center { order: initial; width: auto; flex: 1; gap: 6px; font-size: 0.85rem; overflow-x: auto; padding-bottom: 0; justify-content: center; white-space: nowrap; margin: 0; scrollbar-width: none; }
            .nav-center a { flex-shrink: 0; display: flex; align-items: center; justify-content: center; padding: 5px 12px; font-size: 0.85rem; }
            #installAppBtn i { font-size: 1.15rem; margin: 0 !important; }
            .nav-center::-webkit-scrollbar { display: none; }
            
            .logo-img { height: 32px !important; }
            .profile-icon { width: 30px !important; height: 30px !important; font-size: 1rem !important; }
            .nav-left > a > i { font-size: 1.15rem !important; }
            
            .hero-wrapper { padding: 80px 4% 25px 4%; }
            .hero-carousel { height: 65vh; min-height: 400px; max-height: 550px; border-radius: 8px; }
            .hero-slide { padding-bottom: 4%; }
            .hero-content { width: 100%; }
            .hero-title { font-size: clamp(24px, 8vw, 36px); line-height: 1.2; }
            .hero-meta { font-size: 0.8rem; margin-bottom: 10px; }
            .hero-desc { font-size: 0.85rem; -webkit-line-clamp: 3; }
            .btn { padding: 8px 16px; font-size: 0.95rem; }
            
            .movie-content { flex-direction: column; margin-top: -15vh; }
            .movie-info-right { flex: 1; margin-top: 0; }
            .movie-hero { height: 55vh; }
            .movie-title { font-size: clamp(28px, 8vw, 42px); }
            .slider { --slider-h: max(46vw - 15px, 150px); }
            .nav-links { gap: 15px; }
            .movies-grid { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); }
            .player-controls { padding: 15px; }
        }

    </style>
</head>
<body>

<?php if ($page == 'login'): ?>
    <!-- LOGIN VIEW -->
    <div class="auth-container">
        <img src="ytflix.png" alt="YTFlix" class="logo-img" style="position:absolute; top: 20px; left: 4%; height: 45px;">
        <div class="auth-box">
            <h1>Sign In</h1>
            <?php if(isset($login_error)) echo "<p style='color:var(--primary);margin-bottom:15px;'>$login_error</p>"; ?>
            <form method="POST">
                <input type="text" name="username" placeholder="Username" required class="tv-focusable">
                <input type="password" name="password" placeholder="Password" required class="tv-focusable">
                <button type="submit" name="login" class="tv-focusable">Sign In</button>
            </form>
        </div>
    </div>

<?php elseif ($page == 'profiles'): ?>
    <!-- PROFILES VIEW -->
    <div class="profiles-wrapper">
        <img src="ytflix.png" alt="YTFlix" class="logo-img" style="height: 55px; margin-bottom: 20px;">
        <h2>Who's watching?</h2>
        <?php if(isset($pin_error)) echo "<p style='color:var(--primary); margin-bottom:20px; font-weight:bold; font-size: 1.2rem;'>$pin_error</p>"; ?>
        
        <div class="profiles-list">
            <?php
            $stmt = $pdo->prepare("SELECT * FROM profiles WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $profiles = $stmt->fetchAll();
            foreach ($profiles as $p): 
                $bgStyle = !empty($p['avatar_url']) ? "background-image: url('".htmlspecialchars($p['avatar_url'])."'); background-color: transparent;" : "background-color: ".$p['color'];
                $avatarContent = !empty($p['avatar_url']) ? "" : substr($p['name'], 0, 1);
                $hasPin = !empty($p['pin']) ? 'true' : 'false';
            ?>
                <div class="profile-card tv-focusable" tabindex="0" onclick="handleProfileClick(<?= $p['id'] ?>, <?= $hasPin ?>)">
                    <div class="profile-avatar" style="<?= $bgStyle ?>">
                        <?= htmlspecialchars($avatarContent) ?>
                    </div>
                    <?php if($hasPin === 'true'): ?>
                        <div class="profile-lock">
                            <i class="fas fa-lock"></i>
                        </div>
                    <?php endif; ?>
                    <span><?= htmlspecialchars($p['name']) ?></span>
                </div>
            <?php endforeach; ?>
            
            <div class="profile-card tv-focusable" tabindex="0" onclick="document.getElementById('addProfileModal').style.display='flex'">
                <div class="profile-avatar" style="background:transparent; border: 2px solid grey; color:grey;"><i class="fas fa-plus"></i></div>
                <span>Add Profile</span>
            </div>
        </div>
    </div>
    
    <!-- Add Profile Modal -->
    <div id="addProfileModal" class="modal-overlay">
        <div class="auth-box" style="margin:auto;">
            <h1>Add Profile</h1>
            <form method="POST" enctype="multipart/form-data">
                <input type="text" name="profile_name" placeholder="Name" required class="tv-focusable">
                <label style="color:var(--gray); display:block; margin-bottom:5px; margin-top:10px;">Upload Avatar Image (Optional)</label>
                <input type="file" name="avatar_file" accept="image/*" class="tv-focusable" style="background:#333; padding:12px; width:100%; border-radius:4px;">
                <button type="submit" name="create_profile" class="tv-focusable">Create</button>
                <button type="button" onclick="document.getElementById('addProfileModal').style.display='none'" class="btn tv-focusable" style="width:100%; justify-content:center; background:#333; margin-top:10px; color:white;">Cancel</button>
            </form>
        </div>
    </div>

    <!-- PIN Entry Modal -->
    <div id="pinLockModal" class="modal-overlay" style="z-index: 3000;">
        <div class="auth-box" style="margin:auto; text-align:center;">
            <h2 style="margin-bottom: 20px;">Enter Profile PIN</h2>
            <form method="POST" id="pinForm">
                <input type="hidden" name="login_profile_id" id="loginProfileId">
                <input type="hidden" name="login_pin" id="hiddenPinInput">
                
                <div class="pin-box-container">
                    <input type="password" class="pin-box tv-focusable" id="pinBox1" maxlength="1" inputmode="numeric" pattern="[0-9]*" autocomplete="off">
                    <input type="password" class="pin-box tv-focusable" id="pinBox2" maxlength="1" inputmode="numeric" pattern="[0-9]*" autocomplete="off">
                    <input type="password" class="pin-box tv-focusable" id="pinBox3" maxlength="1" inputmode="numeric" pattern="[0-9]*" autocomplete="off">
                    <input type="password" class="pin-box tv-focusable" id="pinBox4" maxlength="1" inputmode="numeric" pattern="[0-9]*" autocomplete="off">
                </div>
            </form>
            <button type="button" onclick="closePinModal()" class="btn tv-focusable" style="width:100%; justify-content:center; background:#444; margin-top:10px; color:white;">Cancel</button>
        </div>
    </div>

<?php elseif ($page == 'home' || $page == 'movies' || $page == 'shows'): ?>
    <!-- HOME VIEW -->
    <nav id="navbar">
        <div class="nav-links nav-left" style="display:flex; gap:15px; align-items:center;">
            <?php 
                $pBgStyle = !empty($currentProfile['avatar_url']) ? "background-image: url('".htmlspecialchars($currentProfile['avatar_url'])."'); background-color: transparent;" : "background-color: ".$currentProfile['color'];
                $pAvatarContent = !empty($currentProfile['avatar_url']) ? "" : substr($currentProfile['name'], 0, 1);
            ?>
            <div class="profile-icon tv-focusable" tabindex="0" style="<?= $pBgStyle ?>; border-radius: 4px;" onclick="window.location.href='?p=profiles'" title="Switch Profile">
                <?= htmlspecialchars($pAvatarContent) ?>
            </div>
            <a href="?logout=1" class="tv-focusable" title="Logout" style="color:white; opacity:0.8; transition:0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.8'">
                <i class="fas fa-sign-out-alt" style="font-size: 20px;"></i>
            </a>
        </div>

        <div class="nav-center">
            <a href="?p=search" class="tv-focusable <?= $page == 'search' ? 'active' : '' ?>" title="Search"><i class="fas fa-search"></i></a>
            <a href="?p=home" class="tv-focusable <?= $page == 'home' ? 'active' : '' ?>" title="Home"><i class="fas fa-home"></i><span>Home</span></a>
            <a href="?p=movies" class="tv-focusable <?= $page == 'movies' ? 'active' : '' ?>" title="Movies"><i class="fas fa-film"></i><span>Movies</span></a>
            <a href="?p=shows" class="tv-focusable <?= $page == 'shows' ? 'active' : '' ?>" title="Shows"><i class="fas fa-tv"></i><span>Shows</span></a>
            <a href="javascript:void(0)" id="installAppBtn" class="tv-focusable" title="Install App" style="display:none; align-items:center; color: #FFD700; font-weight: bold;"><i class="fas fa-download"></i> <span>Install App</span></a>
            <?php if ($is_main_profile): ?>
            <a href="?p=admin&tab=account" class="tv-focusable <?= $page == 'admin' ? 'active' : '' ?>" title="Settings"><i class="fas fa-cog"></i><span>Settings</span></a>
            <?php endif; ?>
        </div>

        <div class="nav-links nav-right">
            <a href="?p=home" class="tv-focusable" style="display:flex; align-items:center;" title="Home">
                <img src="y.png" alt="YTFlix" class="logo-img" style="height: 50px;">
            </a>
        </div>
    </nav>

    <?php
    $isShowsPage = ($page == 'shows');
    $isMoviesPage = ($page == 'movies');
    $isHomePage = ($page == 'home');
    
    $allMedia = [];
    $allMovies = [];
    $allShows = [];
    $watchlist = [];
    $progressMap = [];
    $continueWatching = [];
    $uniqueGenres = [];

    $qMovies = $pdo->query("SELECT *, 'movie' as media_type FROM movies ORDER BY id DESC");
    $allMovies = $qMovies->fetchAll(PDO::FETCH_ASSOC);

    $qShows = $pdo->query("SELECT *, 'show' as media_type FROM shows ORDER BY id DESC");
    $allShows = $qShows->fetchAll(PDO::FETCH_ASSOC);

    if ($isShowsPage) {
        $allMedia = $allShows;
    } elseif ($isMoviesPage) {
        $allMedia = $allMovies;
    } else {
        $allMedia = array_merge($allMovies, $allShows);
    }

    $wMovies = $pdo->prepare("SELECT m.*, 'movie' as media_type FROM movies m JOIN watchlist w ON m.id = w.movie_id WHERE w.profile_id = ?");
    $wMovies->execute([$_SESSION['profile_id']]);
    $wMoviesData = $wMovies->fetchAll(PDO::FETCH_ASSOC);

    $wShows = $pdo->prepare("SELECT s.*, 'show' as media_type FROM shows s JOIN watchlist w ON s.id = w.show_id WHERE w.profile_id = ?");
    $wShows->execute([$_SESSION['profile_id']]);
    $wShowsData = $wShows->fetchAll(PDO::FETCH_ASSOC);

    if ($isShowsPage) $watchlist = $wShowsData;
    elseif ($isMoviesPage) $watchlist = $wMoviesData;
    else $watchlist = array_merge($wMoviesData, $wShowsData);

    $pMovies = $pdo->prepare("SELECT movie_id as id, progress_time, duration FROM playback_progress WHERE profile_id = ?");
    $pMovies->execute([$_SESSION['profile_id']]);
    foreach($pMovies->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!$isShowsPage) $progressMap[$row['id']] = ['time' => (int)$row['progress_time'], 'duration' => (int)$row['duration']];
    }
    
    $pShows = $pdo->prepare("SELECT episode_id as id, progress_time, duration FROM episode_playback_progress WHERE profile_id = ?");
    $pShows->execute([$_SESSION['profile_id']]);
    foreach($pShows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($isShowsPage || $isHomePage) $progressMap[$row['id']] = ['time' => (int)$row['progress_time'], 'duration' => (int)$row['duration']];
    }

    $cwMoviesQ = $pdo->prepare("SELECT m.*, p.progress_time, p.duration, p.last_watched, 'movie' as media_type FROM movies m JOIN playback_progress p ON m.id = p.movie_id WHERE p.profile_id = ? AND p.progress_time > 0 AND (p.duration = 0 OR p.duration - p.progress_time > 15)");
    $cwMoviesQ->execute([$_SESSION['profile_id']]);
    $cwMoviesData = $cwMoviesQ->fetchAll(PDO::FETCH_ASSOC);

    $cwShowsQ = $pdo->prepare("SELECT e.*, s.id as show_id, s.clean_title as show_title, s.genre as show_genre, s.poster_path as show_poster, s.backdrop_path as show_backdrop, p.progress_time, p.duration, p.last_watched, 'show' as media_type FROM episodes e JOIN episode_playback_progress p ON e.id = p.episode_id JOIN shows s ON e.show_id = s.id WHERE p.profile_id = ? AND p.progress_time > 0 AND (p.duration = 0 OR p.duration - p.progress_time > 15)");
    $cwShowsQ->execute([$_SESSION['profile_id']]);
    $cwShowsData = $cwShowsQ->fetchAll(PDO::FETCH_ASSOC);

    if ($isShowsPage) $continueWatching = $cwShowsData;
    elseif ($isMoviesPage) $continueWatching = $cwMoviesData;
    else $continueWatching = array_merge($cwMoviesData, $cwShowsData);

    usort($continueWatching, function($a, $b) {
        return strtotime($b['last_watched']) - strtotime($a['last_watched']);
    });

    $heroMovies = [];
    if (!empty($allMedia)) {
        $keys = array_rand($allMedia, min(5, count($allMedia)));
        if (!is_array($keys)) $keys = [$keys];
        foreach ($keys as $k) {
            $heroMovies[] = $allMedia[$k];
        }
    }

    $genreQuery1 = $pdo->query("SELECT genre FROM movies WHERE genre IS NOT NULL AND genre != ''");
    $genreQuery2 = $pdo->query("SELECT genre FROM shows WHERE genre IS NOT NULL AND genre != ''");
    $genreRows = array_merge($genreQuery1->fetchAll(PDO::FETCH_COLUMN), $genreQuery2->fetchAll(PDO::FETCH_COLUMN));
    $uniqueGenres = [];
    foreach($genreRows as $gString) {
        $parts = explode(',', $gString);
        foreach($parts as $p) {
            $p = trim($p);
            if(!empty($p) && !in_array($p, $uniqueGenres)) {
                $uniqueGenres[] = $p;
            }
        }
    }
    $_SESSION['home_seed'] = mt_rand();
    mt_srand($_SESSION['home_seed']);
    shuffle($uniqueGenres);
    $randomGenres = array_slice($uniqueGenres, 0, 3);
    
    if(!function_exists('renderSliderItem')) {
        function renderSliderItem($m, $isContinueWatching = false) {
            global $isShowsPage;
            $isShows = isset($m['media_type']) && $m['media_type'] === 'show';
            $id = htmlspecialchars($m['id']);
            $poster = htmlspecialchars(isset($m['poster_path']) ? $m['poster_path'] : (isset($m['show_poster']) ? $m['show_poster'] : ''));
            $backdrop = htmlspecialchars(isset($m['backdrop_path']) && $m['backdrop_path'] ? $m['backdrop_path'] : (isset($m['show_backdrop']) && $m['show_backdrop'] ? $m['show_backdrop'] : $poster));
            $title = htmlspecialchars(isset($m['clean_title']) ? $m['clean_title'] : (isset($m['title']) ? $m['title'] : ''));
            $genre = isset($m['genre']) ? htmlspecialchars(trim(explode(',', $m['genre'])[0])) : (isset($m['show_genre']) && $m['show_genre'] ? htmlspecialchars(trim(explode(',', $m['show_genre'])[0])) : ($isShows ? 'Show' : 'Movie'));
            $year = htmlspecialchars(isset($m['release_year']) ? $m['release_year'] : '');
            $type = $isShows ? 'TV Show' : 'Movie';
            $desc = htmlspecialchars(isset($m['description']) ? $m['description'] : '');
            $targetType = $isShows ? 'show' : 'movie';
            $targetId = $isShows && isset($m['show_id']) ? $m['show_id'] : $id;

            if ($isContinueWatching && isset($m['progress_time']) && isset($m['duration']) && $m['duration'] > 0) {
                $pct = ($m['progress_time'] / $m['duration']) * 100;
                $minsLeft = round(($m['duration'] - $m['progress_time']) / 60);
                $timeLeftStr = $minsLeft > 0 ? $minsLeft . 'm left' : 'Resume Playback';

                echo '
                <div class="slider-item tv-focusable" tabindex="0" data-movie-id="'.$id.'" onclick="var w=this.closest(\'.slider-wrapper\'); sessionStorage.setItem(\'lastMovieId\', \''.$id.'\'); sessionStorage.setItem(\'lastSection\', w?w.id||w.getAttribute(\'data-section\')||\'\':\'\'  ); window.location.href=\'?p='.$targetType.'&id='.$targetId.'\'">
                    <div class="slider-img-box">
                        <img src="'.$poster.'" alt="'.$title.'" class="slider-poster" onerror="this.src=\'https://via.placeholder.com/300x450/222/fff?text=No+Poster\'">
                        <img src="'.$backdrop.'" alt="'.$title.'" class="slider-backdrop" onerror="this.src=\'https://via.placeholder.com/600x337/222/fff?text=No+Image\'">
                        
                        <div class="slider-img-overlay">
                            <div class="slider-resume-btn"><i class="fas fa-play" style="margin-right:5px;"></i> Resume</div>
                        </div>
                        <div class="slider-progress"><div class="slider-progress-fill" style="width: '.$pct.'%;"></div></div>
                    </div>
                    <div class="cw-info">
                        <div class="cw-title">'.$title.'</div>
                        <div class="cw-meta">'.$timeLeftStr.'</div>
                    </div>
                </div>';
            } else {
                echo '
                <div class="slider-item tv-focusable" tabindex="0" data-movie-id="'.$id.'" onclick="var w=this.closest(\'.slider-wrapper\'); sessionStorage.setItem(\'lastMovieId\', \''.$id.'\'); sessionStorage.setItem(\'lastSection\', w?w.id||w.getAttribute(\'data-section\')||\'\':\'\'  ); window.location.href=\'?p='.$targetType.'&id='.$targetId.'\'">
                    <div class="slider-img-box">
                        <img src="'.$poster.'" alt="'.$title.'" class="slider-poster" onerror="this.src=\'https://via.placeholder.com/300x450/222/fff?text=No+Poster\'">
                        <img src="'.$backdrop.'" alt="'.$title.'" class="slider-backdrop" onerror="this.src=\'https://via.placeholder.com/600x337/222/fff?text=No+Image\'">
                        
                        <div class="slider-img-overlay">
                            <div class="slider-img-title">'.$title.'</div>
                        </div>
                    </div>
                    <div class="slider-info">
                        <div class="slider-meta">
                            <span>'.$genre.'</span> <span style="font-size:0.5rem; color:#666;">●</span> <span>'.$year.'</span> <span style="font-size:0.5rem; color:#666;">●</span> <span>'.$type.'</span>
                        </div>
                        <div class="slider-desc">'.$desc.'</div>
                    </div>
                </div>';
            }
        }
    }

    ob_start(); 
    ?>
    <?php if (!empty($heroMovies)): ?>
    <div class="hero-wrapper">
        <div class="hero-carousel" id="heroCarousel">
            <?php foreach($heroMovies as $index => $hm): ?>
                <?php 
                    $hId = $hm['id'];
                    $isHeroShow = isset($hm['media_type']) && $hm['media_type'] === 'show';
                    $heroTime = !$isHeroShow && isset($progressMap[$hId]) ? $progressMap[$hId]['time'] : 0;
                    $heroPlayText = $heroTime > 0 ? "Resume" : "Play";
                    $playAction = $isHeroShow ? "window.location.href='?p=show&id={$hId}'" : "openPlayer('{$hm['yt_video_id']}', '" . addslashes(htmlspecialchars($hm['clean_title'])) . "', {$heroTime}, {$hId})";
                    $moreInfoLink = $isHeroShow ? "?p=show&id={$hId}" : "?p=movie&id={$hId}";
                ?>
                <div class="hero-slide <?= $index === 0 ? 'active' : '' ?>" style="background-image: url('<?= htmlspecialchars($hm['backdrop_path'] ?: $hm['poster_path']) ?>');">
                    <div class="hero-content">
                        <h1 class="hero-title"><?= htmlspecialchars($hm['clean_title']) ?></h1>
                        <div class="hero-meta" style="display:flex; align-items:center; gap:10px; margin-bottom:12px; font-size:0.9rem; color:#ccc;">
                            <?php if($hm['genre']): ?>
                                <?php $formattedGenre = implode(' &bull; ', array_map('trim', explode(',', $hm['genre']))); ?>
                                <span style="font-weight:500; color: white;"><?= $formattedGenre ?></span>
                                <span style="font-size:0.5rem;">●</span>
                            <?php endif; ?>
                            <span style="color:white; font-weight:bold;"><?= htmlspecialchars($hm['release_year']) ?></span>
                        </div>
                        <p class="hero-desc"><?= htmlspecialchars($hm['description']) ?></p>
                        <div class="btn-group">
                            <button class="btn btn-play tv-focusable" onclick="<?= $playAction ?>">
                                <i class="fas fa-play" style="margin-right:5px;"></i> <?= $heroPlayText ?>
                            </button>
                            <button class="btn btn-more tv-focusable" data-movie-id="<?= $hId ?>" onclick="sessionStorage.setItem('lastMovieId', '<?= $hId ?>'); sessionStorage.setItem('lastSection', 'hero'); window.location.href='<?= $moreInfoLink ?>'">
                                <i class="fas fa-info-circle" style="margin-right:5px;"></i> More Info
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="hero-wrapper">
        <div class="hero-carousel">
            <div class="hero-slide active">
                <div class="hero-content">
                    <h1 class="hero-title">Welcome to YTFLIX</h1>
                    <p class="hero-desc">Your database is empty. Click Settings -> Manage Library to sync your YouTube Playlist.</p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Continue Watching Redesign -->
    <?php if(!empty($continueWatching)): ?>
    <div class="slider-wrapper" id="continue-watching-section">
        <div class="slider-header">
            <h2 class="slider-title">Continue Watching</h2>
            <div class="slider-controls">
                <button class="slider-btn tv-focusable" onclick="scrollSlider(this, -1)" title="Scroll Left"><i class="fas fa-chevron-left"></i></button>
                <button class="slider-btn tv-focusable" onclick="scrollSlider(this, 1)" title="Scroll Right"><i class="fas fa-chevron-right"></i></button>
            </div>
        </div>
        <div class="cw-slider slider" id="cw-slider-row">
            <?php foreach($continueWatching as $m): 
                renderSliderItem($m, true);
            endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if(!empty($watchlist)): ?>
    <div class="slider-wrapper" id="watchlist-section">
        <div class="slider-header">
            <h2 class="slider-title">My List</h2>
            <div class="slider-controls">
                <button class="slider-btn tv-focusable" onclick="scrollSlider(this, -1)" title="Scroll Left"><i class="fas fa-chevron-left"></i></button>
                <button class="slider-btn tv-focusable" onclick="scrollSlider(this, 1)" title="Scroll Right"><i class="fas fa-chevron-right"></i></button>
            </div>
        </div>
        <div class="slider row-posters">
            <?php foreach($watchlist as $m): 
                renderSliderItem($m, false);
            endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isMoviesPage || $isHomePage): ?>
    <div class="slider-wrapper" id="movies">
        <div class="slider-header">
            <h2 class="slider-title">All Movies</h2>
            <div class="slider-controls">
                <button class="slider-btn tv-focusable" onclick="scrollSlider(this, -1)" title="Scroll Left"><i class="fas fa-chevron-left"></i></button>
                <button class="slider-btn tv-focusable" onclick="scrollSlider(this, 1)" title="Scroll Right"><i class="fas fa-chevron-right"></i></button>
            </div>
        </div>
        <div class="slider row-posters">
            <?php 
            $shuffledAllMovies = $allMovies; 
            mt_srand($_SESSION['home_seed']);
            shuffle($shuffledAllMovies);     
            foreach($shuffledAllMovies as $m): 
                renderSliderItem($m, false);
            endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isShowsPage || $isHomePage): ?>
    <div class="slider-wrapper" id="shows">
        <div class="slider-header">
            <h2 class="slider-title">All Shows</h2>
            <div class="slider-controls">
                <button class="slider-btn tv-focusable" onclick="scrollSlider(this, -1)" title="Scroll Left"><i class="fas fa-chevron-left"></i></button>
                <button class="slider-btn tv-focusable" onclick="scrollSlider(this, 1)" title="Scroll Right"><i class="fas fa-chevron-right"></i></button>
            </div>
        </div>
        <div class="slider row-posters">
            <?php 
            $shuffledAllShows = $allShows; 
            mt_srand($_SESSION['home_seed']);
            shuffle($shuffledAllShows);     
            foreach($shuffledAllShows as $s): 
                renderSliderItem($s, false);
            endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php foreach($randomGenres as $genreName): ?>
        <?php
            $genreMovies = array_filter($allMedia, function($m) use ($genreName) {
                $mGenre = isset($m['genre']) ? $m['genre'] : (isset($m['show_genre']) ? $m['show_genre'] : '');
                return stripos($mGenre, $genreName) !== false;
            });
            if(count($genreMovies) > 0):
        ?>
        <div class="slider-wrapper" data-section="genre-<?= htmlspecialchars(strtolower(str_replace(' ', '-', $genreName))) ?>">
            <div class="slider-header">
                <h2 class="slider-title">Because you like <?= htmlspecialchars($genreName) ?></h2>
                <div class="slider-controls">
                    <button class="slider-btn tv-focusable" onclick="scrollSlider(this, -1)" title="Scroll Left"><i class="fas fa-chevron-left"></i></button>
                    <button class="slider-btn tv-focusable" onclick="scrollSlider(this, 1)" title="Scroll Right"><i class="fas fa-chevron-right"></i></button>
                </div>
            </div>
            <div class="slider row-posters">
                <?php foreach($genreMovies as $m): 
                    renderSliderItem($m, false);
                endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php
    $homeHtml = ob_get_clean(); 

    if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_home_data') {
        ob_clean(); 
        header('Content-Type: application/json');
        echo json_encode([
            'html' => $homeHtml,
            'watchlistIds' => array_map('intval', array_column($watchlist, 'id')),
            'progressMap' => $progressMap
        ]);
        exit;
    }

    echo '<div id="home-content">' . $homeHtml . '</div>';
    ?>
    
    <div style="padding-bottom: 50px;"></div>



    <!-- Custom Video Player -->
    <div id="playerContainer" class="player-container">
        <div id="yt-iframe-wrapper">
            <div id="ytplayer"></div>
        </div>
        <div id="playerOverlay" style="position:absolute; inset:0; z-index:1;"></div>
        
        <div id="playerControls" class="player-controls" style="z-index:2;">
            <div class="player-header">
                <button class="tv-focusable player-btn" onclick="closePlayer()" style="font-size:1.8rem;">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <span id="playerTitle" style="font-weight:bold; flex-grow: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"></span>
                <span id="playerEndTime" style="font-size:1rem; color:#ccc; white-space: nowrap; margin-left: 15px;"></span>
            </div>
            
            <div id="universalPlayerMenu" style="display:none; position:absolute; bottom:90px; right:30px; background:rgba(0,0,0,0.9); border:1px solid #333; border-radius:8px; padding:10px; flex-direction:column; gap:5px; min-width:150px; max-height: 250px; overflow-y: auto; z-index:20;"></div>

            <div class="player-bottom">
                <div class="progress-bar tv-focusable" id="progressBar" onclick="seekVideo(event)">
                    <div class="progress-filled" id="progressFilled"></div>
                </div>
                <div class="controls-row">
                    <div class="play-controls-group" style="display:flex; align-items:center; gap:15px;">
                        <button class="tv-focusable player-btn" onclick="skip(-10)" title="Back 10 Seconds"><i class="fas fa-undo"></i></button>
                        <button class="tv-focusable player-btn" id="playPauseBtn" onclick="togglePlay()"><i class="fas fa-pause"></i></button>
                        <button class="tv-focusable player-btn" onclick="skip(10)" title="Forward 10 Seconds"><i class="fas fa-redo"></i></button>
                        <span class="time-display tv-focusable" style="font-size:1rem; margin-left: 10px; cursor: pointer; user-select: none;" id="timeDisplay" onclick="toggleTimeDisplay()" tabindex="0">0:00 / 0:00</span>
                    </div>
                    
                    <div style="flex-grow:1"></div>
                    
                    <div class="player-settings" style="display:flex; align-items:center; gap:15px;">
                        <button class="tv-focusable player-btn" id="ccBtn" onclick="toggleCCMenu()" title="Subtitles / CC">
                            <i class="fas fa-closed-captioning" style="font-size:1.5rem;"></i>
                        </button>
                        <button class="tv-focusable player-btn" id="speedBtn" onclick="toggleSpeed()" title="Playback Speed" style="font-weight:bold; font-size:1.1rem;">
                            1x
                        </button>
                        <button class="tv-focusable player-btn" onclick="toggleFullscreen()" title="Fullscreen">
                            <i class="fas fa-expand" style="font-size:1.5rem;"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($page == 'search'): ?>
    <!-- SEARCH VIEW -->
    <nav id="navbar">
        <div class="nav-links nav-left" style="display:flex; gap:15px; align-items:center;">
            <?php 
                $pBgStyle = !empty($currentProfile['avatar_url']) ? "background-image: url('".htmlspecialchars($currentProfile['avatar_url'])."'); background-color: transparent;" : "background-color: ".$currentProfile['color'];
                $pAvatarContent = !empty($currentProfile['avatar_url']) ? "" : substr($currentProfile['name'], 0, 1);
            ?>
            <div class="profile-icon tv-focusable" tabindex="0" style="<?= $pBgStyle ?>; border-radius: 4px;" onclick="window.location.href='?p=profiles'" title="Switch Profile">
                <?= htmlspecialchars($pAvatarContent) ?>
            </div>
            <a href="?logout=1" class="tv-focusable" title="Logout" style="color:white; opacity:0.8; transition:0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.8'">
                <i class="fas fa-sign-out-alt" style="font-size: 20px;"></i>
            </a>
        </div>
        <div class="nav-center">
            <a href="?p=search" class="tv-focusable <?= $page == 'search' ? 'active' : '' ?>" title="Search"><i class="fas fa-search"></i></a>
            <a href="?p=home" class="tv-focusable <?= $page == 'home' ? 'active' : '' ?>" title="Home"><i class="fas fa-home"></i><span>Home</span></a>
            <a href="?p=movies" class="tv-focusable <?= $page == 'movies' ? 'active' : '' ?>" title="Movies"><i class="fas fa-film"></i><span>Movies</span></a>
            <a href="?p=shows" class="tv-focusable <?= $page == 'shows' ? 'active' : '' ?>" title="Shows"><i class="fas fa-tv"></i><span>Shows</span></a>
            <a href="?p=admin&tab=account" class="tv-focusable <?= $page == 'admin' ? 'active' : '' ?>" title="Settings"><i class="fas fa-cog"></i><span>Settings</span></a>
        </div>
        <div class="nav-links nav-right">
            <a href="?p=home" class="tv-focusable" style="display:flex; align-items:center;" title="Home">
                <img src="y.png" alt="YTFlix" class="logo-img" style="height: 50px;">
            </a>
        </div>
    </nav>

    <?php
    // Fetch all movies
    $allSearchMovies = $pdo->query("SELECT * FROM movies ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    
    // Get continue watching IDs to exclude from recommendations
    $cwExcludeStmt = $pdo->prepare("SELECT movie_id FROM playback_progress WHERE profile_id = ? AND progress_time > 0");
    $cwExcludeStmt->execute([$_SESSION['profile_id']]);
    $cwExcludeIds = $cwExcludeStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Filter out continue watching movies for recommendations
    $recommendMovies = array_filter($allSearchMovies, function($m) use ($cwExcludeIds) {
        return !in_array($m['id'], $cwExcludeIds);
    });
    $recommendMovies = array_values($recommendMovies);
    mt_srand($_SESSION['home_seed']);
    shuffle($recommendMovies);
    $recommendMovies = array_slice($recommendMovies, 0, 20);
    
    // Get unique genres for the genre list
    $searchGenres = [];
    foreach($allSearchMovies as $m) {
        if (!empty($m['genre'])) {
            foreach(explode(',', $m['genre']) as $g) {
                $g = trim($g);
                if (!empty($g) && !in_array($g, $searchGenres)) $searchGenres[] = $g;
            }
        }
    }
    sort($searchGenres);
    ?>

    <div class="search-page">
        <div class="search-left">
            <input type="text" id="searchInput" class="search-input-box tv-focusable" placeholder="Search movies..." autocomplete="off" readonly>

            <div class="vk-grid" id="virtualKeyboard">
                <button class="vk-key vk-wide vk-action tv-focusable" data-action="space" title="Space"><span style="letter-spacing:1px; font-weight:bold; font-size:0.9rem;">SPACE</span></button>
                <button class="vk-key vk-wide vk-action tv-focusable" data-action="backspace" title="Delete"><i class="fas fa-backspace"></i></button>
                <?php
                $keys = array_merge(range('a','z'), range('0','9'));
                foreach($keys as $k):
                ?>
                    <button class="vk-key tv-focusable" data-char="<?= $k ?>"><?= $k ?></button>
                <?php endforeach; ?>
                <button class="vk-key vk-action tv-focusable" style="grid-column: span 6;" data-action="clear" title="Clear All"><i class="fas fa-times" style="margin-right:6px;"></i> Clear</button>
            </div>
        </div>

        <div class="search-right">
            <h2 id="searchResultsTitle">Your Search Recommendations</h2>
            <div class="search-grid" id="searchResultsGrid">
                <?php foreach($recommendMovies as $m): ?>
                    <div class="search-card tv-focusable" tabindex="0" data-movie-id="<?= $m['id'] ?>" onclick="sessionStorage.setItem('lastMovieId', '<?= $m['id'] ?>'); sessionStorage.setItem('lastSection', 'search'); window.location.href='?p=movie&id=<?= $m['id'] ?>'">
                        <img src="<?= htmlspecialchars($m['poster_path'] ?: $m['backdrop_path']) ?>" alt="<?= htmlspecialchars($m['clean_title']) ?>" onerror="this.src='https://via.placeholder.com/300x450/222/fff?text=No+Poster'">
                        <div class="search-card-overlay">
                            <div class="search-card-title"><?= htmlspecialchars($m['clean_title']) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if(empty($recommendMovies)): ?>
                    <div class="search-no-results">No recommendations available. Sync your library first.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php elseif ($page == 'movie'): ?>
    <?php
    $movieId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $stmt = $pdo->prepare("SELECT * FROM movies WHERE id = ?");
    $stmt->execute([$movieId]);
    $movie = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$movie) {
        echo "<h2 style='color:white; text-align:center; margin-top:100px;'>Movie not found. <a href='?p=home' style='color:var(--primary);'>Go Back</a></h2>";
        exit;
    }

    $inWatchlist = false;
    $wStmt = $pdo->prepare("SELECT id FROM watchlist WHERE profile_id = ? AND movie_id = ?");
    $wStmt->execute([$_SESSION['profile_id'], $movieId]);
    if ($wStmt->fetch()) $inWatchlist = true;

    $progressStmt = $pdo->prepare("SELECT progress_time, duration FROM playback_progress WHERE profile_id = ? AND movie_id = ?");
    $progressStmt->execute([$_SESSION['profile_id'], $movieId]);
    $prog = $progressStmt->fetch(PDO::FETCH_ASSOC);
    $time = $prog ? (int)$prog['progress_time'] : 0;
    $playText = $time > 0 ? "Resume" : "Play";

    $heroBg = $movie['backdrop_path'] ? $movie['backdrop_path'] : $movie['poster_path'];
    $actors = [];
    if($movie['actors']) {
        $parsed = json_decode($movie['actors'], true);
        if(is_array($parsed)) $actors = $parsed;
    }
    ?>
    
    <nav id="navbar">
        <div class="nav-links nav-left">
            <a href="javascript:history.back()" class="tv-focusable" style="color:white; font-size: 1.5rem; display:flex; align-items:center; gap:10px; text-decoration: none;">
                <i class="fas fa-arrow-left"></i> <span style="font-size:1rem; font-weight:bold;">Back</span>
            </a>
        </div>
        <div class="nav-links nav-right">
            <a href="?p=home" class="tv-focusable" style="display:flex; align-items:center;" title="Home">
                <img src="y.png" alt="YTFlix" class="logo-img" style="height: 50px;">
            </a>
        </div>
    </nav>

    <div class="movie-page-container">
        <div class="movie-hero" style="background-image: url('<?= htmlspecialchars($heroBg) ?>');">
            <div class="movie-hero-gradient"></div>
        </div>
        
        <div class="movie-content">
            <div class="movie-info-left">
                <h1 class="movie-title"><?= htmlspecialchars($movie['clean_title']) ?></h1>
                
                <div class="movie-meta">
                    <span class="movie-year"><?= htmlspecialchars($movie['release_year']) ?></span>
                    <?php if($movie['genre']): ?>
                        <span class="movie-genre"><?= htmlspecialchars($movie['genre']) ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="movie-actions">
                    <button class="btn btn-play tv-focusable" onclick="openPlayer('<?= $movie['yt_video_id'] ?>', '<?= addslashes(htmlspecialchars($movie['clean_title'])) ?>', <?= $time ?>, <?= $movieId ?>)">
                        <i class="fas fa-play" style="margin-right: 5px;"></i> <?= $playText ?>
                    </button>
                    
                    <button class="btn btn-icon tv-focusable" id="moviePageWatchlistBtn" onclick="toggleMoviePageWatchlist(<?= $movieId ?>)">
                        <?php if($inWatchlist): ?>
                            <i class="fas fa-check"></i>
                        <?php else: ?>
                            <i class="fas fa-plus"></i>
                        <?php endif; ?>
                    </button>
                </div>
                
                <p class="movie-desc tv-focusable" tabindex="0"><?= htmlspecialchars($movie['description'] ?: 'No description available.') ?></p>
            </div>
            
            <div class="movie-info-right">
                <div class="cast-section">
                    <h3 class="cast-header">Cast</h3>
                    <?php if(!empty($actors)): ?>
                        <div class="cast-grid">
                            <?php foreach($actors as $actor): 
                                $img = $actor['profile'] ? $actor['profile'] : 'avatar-cast.jpg';
                            ?>
                            <div class="cast-card tv-focusable" tabindex="0">
                                <img src="<?= htmlspecialchars($img) ?>" class="cast-img" onerror="this.src='avatar-cast.jpg'">
                                <div class="cast-details">
                                    <div class="cast-name"><?= htmlspecialchars($actor['name']) ?></div>
                                    <div class="cast-char"><?= htmlspecialchars($actor['character']) ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p style="color:#aaa;">Unknown Cast</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Custom Video Player (Movie page) -->
    <div id="playerContainer" class="player-container">
        <div id="yt-iframe-wrapper">
            <div id="ytplayer"></div>
        </div>
        <div id="playerOverlay" style="position:absolute; inset:0; z-index:1;"></div>
        <div id="playerControls" class="player-controls" style="z-index:2;">
            <div class="player-header">
                <button class="tv-focusable player-btn" onclick="closePlayer()" style="font-size:1.8rem;">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <span id="playerTitle" style="font-weight:bold; flex-grow: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"></span>
                <span id="playerEndTime" style="font-size:1rem; color:#ccc; white-space: nowrap; margin-left: 15px;"></span>
            </div>
            <div id="universalPlayerMenu" style="display:none; position:absolute; bottom:90px; right:30px; background:rgba(0,0,0,0.9); border:1px solid #333; border-radius:8px; padding:10px; flex-direction:column; gap:5px; min-width:150px; max-height: 250px; overflow-y: auto; z-index:20;"></div>
            <div class="player-bottom">
                <div class="progress-bar" id="progressBar" onclick="seekVideo(event)">
                    <div class="progress-filled" id="progressFilled"></div>
                </div>
                <div class="controls-row">
                    <div class="play-controls-group" style="display:flex; align-items:center; gap:15px;">
                        <button class="tv-focusable player-btn" onclick="skip(-10)" title="Back 10 Seconds"><i class="fas fa-undo"></i></button>
                        <button class="tv-focusable player-btn" id="playPauseBtn" onclick="togglePlay()"><i class="fas fa-pause"></i></button>
                        <button class="tv-focusable player-btn" onclick="skip(10)" title="Forward 10 Seconds"><i class="fas fa-redo"></i></button>
                        <span class="time-display tv-focusable" style="font-size:1rem; margin-left: 10px; cursor: pointer; user-select: none;" id="timeDisplay" onclick="toggleTimeDisplay()" tabindex="0">0:00 / 0:00</span>
                    </div>
                    <div style="flex-grow:1"></div>
                    <div class="player-settings" style="display:flex; align-items:center; gap:15px;">
                        <button class="tv-focusable player-btn" id="ccBtn" onclick="toggleCCMenu()" title="Subtitles / CC">
                            <i class="fas fa-closed-captioning" style="font-size:1.5rem;"></i>
                        </button>
                        <button class="tv-focusable player-btn" id="speedBtn" onclick="toggleSpeed()" title="Playback Speed" style="font-weight:bold; font-size:1.1rem;">1x</button>
                        <button class="tv-focusable player-btn" onclick="toggleFullscreen()" title="Fullscreen">
                            <i class="fas fa-expand" style="font-size:1.5rem;"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Custom Video Player (Search page) -->
    <div id="playerContainer" class="player-container">
        <div id="yt-iframe-wrapper">
            <div id="ytplayer"></div>
        </div>
        <div id="playerOverlay" style="position:absolute; inset:0; z-index:1;"></div>
        <div id="playerControls" class="player-controls" style="z-index:2;">
            <div class="player-header">
                <button class="tv-focusable player-btn" onclick="closePlayer()" style="font-size:1.8rem;">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <span id="playerTitle" style="font-weight:bold; flex-grow: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"></span>
                <span id="playerEndTime" style="font-size:1rem; color:#ccc; white-space: nowrap; margin-left: 15px;"></span>
            </div>
            <div id="universalPlayerMenu" style="display:none; position:absolute; bottom:90px; right:30px; background:rgba(0,0,0,0.9); border:1px solid #333; border-radius:8px; padding:10px; flex-direction:column; gap:5px; min-width:150px; max-height: 250px; overflow-y: auto; z-index:20;"></div>
            <div class="player-bottom">
                <div class="progress-bar" id="progressBar" onclick="seekVideo(event)">
                    <div class="progress-filled" id="progressFilled"></div>
                </div>
                <div class="controls-row">
                    <div class="play-controls-group" style="display:flex; align-items:center; gap:15px;">
                        <button class="tv-focusable player-btn" onclick="skip(-10)" title="Back 10 Seconds"><i class="fas fa-undo"></i></button>
                        <button class="tv-focusable player-btn" id="playPauseBtn" onclick="togglePlay()"><i class="fas fa-pause"></i></button>
                        <button class="tv-focusable player-btn" onclick="skip(10)" title="Forward 10 Seconds"><i class="fas fa-redo"></i></button>
                        <span class="time-display tv-focusable" style="font-size:1rem; margin-left: 10px; cursor: pointer; user-select: none;" id="timeDisplay" onclick="toggleTimeDisplay()" tabindex="0">0:00 / 0:00</span>
                    </div>
                    <div style="flex-grow:1"></div>
                    <div class="player-settings" style="display:flex; align-items:center; gap:15px;">
                        <button class="tv-focusable player-btn" id="ccBtn" onclick="toggleCCMenu()" title="Subtitles / CC">
                            <i class="fas fa-closed-captioning" style="font-size:1.5rem;"></i>
                        </button>
                        <button class="tv-focusable player-btn" id="speedBtn" onclick="toggleSpeed()" title="Playback Speed" style="font-weight:bold; font-size:1.1rem;">1x</button>
                        <button class="tv-focusable player-btn" onclick="toggleFullscreen()" title="Fullscreen">
                            <i class="fas fa-expand" style="font-size:1.5rem;"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($page == 'show'): ?>
    <?php
    $showId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $stmt = $pdo->prepare("SELECT * FROM shows WHERE id = ?");
    $stmt->execute([$showId]);
    $show = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$show) {
        echo "<h2 style='color:white; text-align:center; margin-top:100px;'>Show not found. <a href='?p=home' style='color:var(--primary);'>Go Back</a></h2>";
        exit;
    }

    $inWatchlist = false;
    $wStmt = $pdo->prepare("SELECT id FROM watchlist WHERE profile_id = ? AND show_id = ?");
    $wStmt->execute([$_SESSION['profile_id'], $showId]);
    if ($wStmt->fetch()) $inWatchlist = true;

    // Fetch episodes
    $epStmt = $pdo->prepare("SELECT * FROM episodes WHERE show_id = ? ORDER BY episode_number ASC");
    $epStmt->execute([$showId]);
    $episodes = $epStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch progress for all episodes
    $progressStmt = $pdo->prepare("SELECT episode_id, progress_time, duration, last_watched FROM episode_playback_progress WHERE profile_id = ?");
    $progressStmt->execute([$_SESSION['profile_id']]);
    $allProgress = $progressStmt->fetchAll(PDO::FETCH_ASSOC);
    $progressMap = [];
    $latestWatchedEpId = 0;
    $latestWatchedTime = 0;
    $maxLastWatched = 0;

    foreach($allProgress as $prog) {
        $progressMap[$prog['episode_id']] = [
            'time' => (int)$prog['progress_time'],
            'duration' => (int)$prog['duration']
        ];
        $lw = strtotime($prog['last_watched']);
        if ($lw > $maxLastWatched) {
            $maxLastWatched = $lw;
            $latestWatchedEpId = $prog['episode_id'];
            $latestWatchedTime = (int)$prog['progress_time'];
        }
    }

    $heroBg = $show['backdrop_path'] ? $show['backdrop_path'] : $show['poster_path'];
    $actors = [];
    if(isset($show['actors']) && $show['actors']) {
        $parsed = json_decode($show['actors'], true);
        if(is_array($parsed)) $actors = $parsed;
    }

    // Determine the episode to play when clicking the main "Play" button
    $mainPlayEpId = 0;
    $mainPlayYtId = '';
    $mainPlayTitle = '';
    $mainPlayTime = 0;
    $mainPlayText = "Play";

    if (count($episodes) > 0) {
        $firstEp = $episodes[0];
        $mainPlayEpId = $firstEp['id'];
        $mainPlayYtId = $firstEp['yt_video_id'];
        $mainPlayTitle = "E" . $firstEp['episode_number'] . " " . $firstEp['title'];
        $mainPlayTime = isset($progressMap[$mainPlayEpId]) ? $progressMap[$mainPlayEpId]['time'] : 0;
        
        foreach($episodes as $ep) {
            if ($ep['id'] == $latestWatchedEpId) {
                $mainPlayEpId = $ep['id'];
                $mainPlayYtId = $ep['yt_video_id'];
                $mainPlayTitle = "E" . $ep['episode_number'] . " " . $ep['title'];
                $mainPlayTime = $latestWatchedTime;
                $mainPlayText = $mainPlayTime > 0 ? "Resume E" . $ep['episode_number'] : "Play E" . $ep['episode_number'];
                break;
            }
        }
    }

    echo "<script>
        var showEpisodes = " . json_encode(array_map(function($e) {
            return [
                'id' => $e['id'],
                'yt_video_id' => $e['yt_video_id'],
                'title' => 'E' . $e['episode_number'] . ' ' . $e['title'],
                'episode_number' => $e['episode_number']
            ];
        }, $episodes)) . ";
    </script>";
    ?>
    
    <nav id="navbar">
        <div class="nav-links nav-left">
            <a href="javascript:history.back()" class="tv-focusable" style="color:white; font-size: 1.5rem; display:flex; align-items:center; gap:10px; text-decoration: none;">
                <i class="fas fa-arrow-left"></i> <span style="font-size:1rem; font-weight:bold;">Back</span>
            </a>
        </div>
        <div class="nav-links nav-right">
            <a href="?p=shows" class="tv-focusable" style="display:flex; align-items:center;" title="Shows">
                <img src="y.png" alt="YTFlix" class="logo-img" style="height: 50px;">
            </a>
        </div>
    </nav>

    <div class="movie-page-container">
        <div class="movie-hero" style="background-image: url('<?= htmlspecialchars($heroBg) ?>');">
            <div class="movie-hero-gradient"></div>
        </div>
        
        <div class="movie-content">
            <div class="movie-info-left">
                <h1 class="movie-title"><?= htmlspecialchars($show['clean_title']) ?></h1>
                
                <div class="movie-meta">
                    <span class="movie-year"><?= htmlspecialchars($show['release_year']) ?></span>
                    <?php if($show['genre']): ?>
                        <span class="movie-genre"><?= htmlspecialchars($show['genre']) ?></span>
                    <?php endif; ?>
                    <span style="font-weight:bold; border:1px solid rgba(255,255,255,0.4); padding: 2px 6px; border-radius:4px; font-size:0.85rem; margin-left: 10px;"><?= count($episodes) ?> Episodes</span>
                </div>
                
                <div class="movie-actions">
                    <?php if($mainPlayEpId): ?>
                    <button class="btn btn-play tv-focusable" onclick="openPlayer('<?= $mainPlayYtId ?>', '<?= addslashes(htmlspecialchars($show['clean_title'] . ' - ' . $mainPlayTitle)) ?>', <?= $mainPlayTime ?>, <?= $mainPlayEpId ?>, 'episode')">
                        <i class="fas fa-play" style="margin-right: 5px;"></i> <?= $mainPlayText ?>
                    </button>
                    <?php endif; ?>
                    
                    <button class="btn btn-icon tv-focusable" id="showPageWatchlistBtn" onclick="toggleShowPageWatchlist(<?= $showId ?>)">
                        <?php if($inWatchlist): ?>
                            <i class="fas fa-check"></i>
                        <?php else: ?>
                            <i class="fas fa-plus"></i>
                        <?php endif; ?>
                    </button>
                </div>
                
                <p class="movie-desc tv-focusable" tabindex="0"><?= htmlspecialchars($show['description'] ?: 'No description available.') ?></p>
            </div>
            
            <div class="movie-info-right">
                <div class="cast-section">
                    <h3 class="cast-header">Cast</h3>
                    <?php if(!empty($actors)): ?>
                        <div class="cast-grid">
                            <?php foreach($actors as $actor): 
                                $img = $actor['profile'] ?? $actor['profile_path'] ?? 'avatar-cast.jpg';
                                $character = $actor['character'] ?? '';
                            ?>
                            <div class="cast-card tv-focusable" tabindex="0">
                                <img src="<?= htmlspecialchars($img) ?>" class="cast-img" onerror="this.src='avatar-cast.jpg'">
                                <div class="cast-details">
                                    <div class="cast-name"><?= htmlspecialchars($actor['name'] ?? 'Unknown Actor') ?></div>
                                    <div class="cast-char"><?= htmlspecialchars($character) ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p style="color:#aaa;">Unknown Cast</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div style="padding: 0 4% 50px 4%; position: relative; z-index: 10; max-width: 1200px; margin: 0 auto;">
            <h2 style="font-size: clamp(24px, 3vw, 36px); font-weight: bold; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #333;">Episodes</h2>
            
            <?php if(empty($episodes)): ?>
                <div style="color: #808080; font-size: 1.2rem; padding: 40px 0; text-align: center; background: rgba(0,0,0,0.4); border-radius: 12px; border: 1px solid #333;">No episodes found for this show.</div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 15px;">
                    <?php foreach($episodes as $ep): 
                        $epProg = isset($progressMap[$ep['id']]) ? $progressMap[$ep['id']] : null;
                        $epTime = $epProg ? $epProg['time'] : 0;
                        $epDuration = $epProg ? $epProg['duration'] : 0;
                        $epPct = ($epDuration > 0 && $epTime > 0) ? ($epTime / $epDuration) * 100 : 0;
                        $thumbnail = $ep['thumbnail_path'] ?: $heroBg;
                        $isActive = ($ep['id'] == $mainPlayEpId);
                    ?>
                    <div class="tv-focusable" style="display: flex; flex-direction: row; gap: 20px; padding: 15px; border-radius: 12px; background: <?= $isActive ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.4)' ?>; border: 2px solid <?= $isActive ? 'rgba(255,255,255,0.5)' : 'transparent' ?>; cursor: pointer; transition: all 0.2s;" tabindex="0" onclick="openPlayer('<?= $ep['yt_video_id'] ?>', '<?= addslashes(htmlspecialchars($show['clean_title'] . ' - E' . $ep['episode_number'] . ' ' . $ep['title'])) ?>', <?= $epTime ?>, <?= $ep['id'] ?>, 'episode')">
                        <div style="position: relative; width: 250px; flex-shrink: 0; aspect-ratio: 16/9; border-radius: 8px; overflow: hidden; background: #222;">
                            <img src="<?= htmlspecialchars($thumbnail) ?>" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.src='https://via.placeholder.com/600x337/222/fff?text=Episode'">
                            <?php if($epPct > 0): ?>
                                <div style="position: absolute; bottom: 0; left: 0; width: 100%; height: 4px; background: rgba(255,255,255,0.3);">
                                    <div style="height: 100%; background: var(--primary); width: <?= $epPct ?>%;"></div>
                                </div>
                            <?php endif; ?>
                            <div style="position: absolute; top: 10px; left: 10px; background: rgba(0,0,0,0.8); padding: 2px 8px; border-radius: 4px; font-size: 1.2rem; font-weight: bold; color: white;"><?= $ep['episode_number'] ?></div>
                        </div>
                        <div style="flex: 1; display: flex; flex-direction: column; justify-content: center;">
                            <h3 style="font-size: 1.3rem; font-weight: bold; margin-bottom: 8px; color: <?= $isActive ? 'var(--primary)' : 'white' ?>;"><?= htmlspecialchars($ep['title']) ?></h3>
                            <p style="color: #aaa; font-size: 0.95rem; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.5; margin: 0;"><?= htmlspecialchars($ep['description']) ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Custom Video Player (Show page) -->
    <div id="playerContainer" class="player-container">
        <div id="yt-iframe-wrapper">
            <div id="ytplayer"></div>
        </div>
        <div id="playerOverlay" style="position:absolute; inset:0; z-index:1;"></div>
        <div id="playerControls" class="player-controls" style="z-index:2;">
            <div class="player-header">
                <button class="tv-focusable player-btn" onclick="closePlayer()" style="font-size:1.8rem;">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <span id="playerTitle" style="font-weight:bold; flex-grow: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"></span>
                <span id="playerEndTime" style="font-size:1rem; color:#ccc; white-space: nowrap; margin-left: 15px;"></span>
            </div>
            <div id="universalPlayerMenu" style="display:none; position:absolute; bottom:90px; right:30px; background:rgba(0,0,0,0.9); border:1px solid #333; border-radius:8px; padding:10px; flex-direction:column; gap:5px; min-width:150px; max-height: 250px; overflow-y: auto; z-index:20;"></div>
            <div class="player-bottom">
                <div class="progress-bar" id="progressBar" onclick="seekVideo(event)">
                    <div class="progress-filled" id="progressFilled"></div>
                </div>
                <div class="controls-row">
                    <div class="play-controls-group" style="display:flex; align-items:center; gap:15px;">
                        <button class="tv-focusable player-btn" onclick="playPreviousEpisode()" title="Previous Episode" id="prevEpBtn" style="display:none;"><i class="fas fa-step-backward"></i></button>
                        <button class="tv-focusable player-btn" onclick="skip(-10)" title="Back 10 Seconds"><i class="fas fa-undo"></i></button>
                        <button class="tv-focusable player-btn" id="playPauseBtn" onclick="togglePlay()"><i class="fas fa-pause"></i></button>
                        <button class="tv-focusable player-btn" onclick="skip(10)" title="Forward 10 Seconds"><i class="fas fa-redo"></i></button>
                        <button class="tv-focusable player-btn" onclick="playNextEpisode()" title="Next Episode" id="nextEpBtn" style="display:none;"><i class="fas fa-step-forward"></i></button>
                        <span class="time-display tv-focusable" style="font-size:1rem; margin-left: 10px; cursor: pointer; user-select: none;" id="timeDisplay" onclick="toggleTimeDisplay()" tabindex="0">0:00 / 0:00</span>
                    </div>
                    <div style="flex-grow:1"></div>
                    <div class="player-settings" style="display:flex; align-items:center; gap:15px;">
                        <button class="tv-focusable player-btn" id="ccBtn" onclick="toggleCCMenu()" title="Subtitles / CC">
                            <i class="fas fa-closed-captioning" style="font-size:1.5rem;"></i>
                        </button>
                        <button class="tv-focusable player-btn" id="speedBtn" onclick="toggleSpeed()" title="Playback Speed" style="font-weight:bold; font-size:1.1rem;">1x</button>
                        <button class="tv-focusable player-btn" onclick="toggleFullscreen()" title="Fullscreen">
                            <i class="fas fa-expand" style="font-size:1.5rem;"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($page == 'admin'): ?>
    <?php $activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'account'; ?>
    <!-- ADMIN VIEW -->
    <nav style="background: var(--bg); position:relative; padding: 20px 4%;">
        <div class="nav-links nav-left">
            <a href="?p=home" class="tv-focusable" style="display:flex; align-items:center;">
                <img src="ytflix.png" alt="YTFlix" class="logo-img" style="height: 35px;">
            </a>
            <a href="?logout=1" class="tv-focusable" title="Logout" style="color:white; opacity:0.8; transition:0.2s; margin-left: 10px;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.8'">
                <i class="fas fa-sign-out-alt" style="font-size: 20px;"></i>
            </a>
        </div>
        <div class="nav-links nav-right" style="display:flex; gap: 20px; align-items:center;">
            <a href="?p=home" class="tv-focusable" style="color:white; font-weight:bold; font-size:1rem;"><i class="fas fa-arrow-left"></i> Back to Home</a>
        </div>
    </nav>

    <div class="admin-panel">
        <div class="admin-nav-tabs">
            <div class="admin-tab tv-focusable <?= $activeTab == 'account' ? 'active' : '' ?>" onclick="window.location.href='?p=admin&tab=account'" tabindex="0">Account Settings</div>
            <?php if ($is_main_profile): ?>
            <div class="admin-tab tv-focusable <?= $activeTab == 'library' ? 'active' : '' ?>" onclick="window.location.href='?p=admin&tab=library'" tabindex="0">Manage Movies</div>
            <div class="admin-tab tv-focusable <?= $activeTab == 'shows' ? 'active' : '' ?>" onclick="window.location.href='?p=admin&tab=shows'" tabindex="0">Manage Shows</div>
            <?php endif; ?>
        </div>

        <?php if($activeTab == 'account'): ?>
            <?php if(isset($_GET['saved'])) echo "<div style='background:green; padding:10px; margin-bottom:20px; border-radius:4px;'>System Configuration Saved.</div>"; ?>
            <?php if(isset($_GET['pw_saved'])) echo "<div style='background:green; padding:10px; margin-bottom:20px; border-radius:4px;'>Password Updated Successfully.</div>"; ?>
            <?php if(isset($_GET['profile_added'])) echo "<div style='background:green; padding:10px; margin-bottom:20px; border-radius:4px;'>Profile Database Updated.</div>"; ?>
            <?php if(isset($_GET['profile_updated'])) echo "<div style='background:green; padding:10px; margin-bottom:20px; border-radius:4px;'>Profile Successfully Updated.</div>"; ?>
            <?php if(isset($_GET['profile_deleted'])) echo "<div style='background:#E50914; padding:10px; margin-bottom:20px; border-radius:4px;'>Profile Successfully Deleted.</div>"; ?>

            <?php if ($is_main_profile): ?>
            <div class="admin-card">
                <h2>System Configuration</h2>
                <p style="color:var(--gray); margin-bottom:20px;">Enter your API keys to enable automatic metadata fetching.</p>
                <form method="POST" class="admin-form">
                    <label>YouTube Data API v3 Key</label>
                    <input type="text" name="yt_api" value="<?= htmlspecialchars($currentUser['yt_api_key']) ?>" class="tv-focusable">
                    
                    <label>TMDB API Key (v3 auth)</label>
                    <input type="text" name="tmdb_api" value="<?= htmlspecialchars($currentUser['tmdb_api_key']) ?>" class="tv-focusable">
                    
                    <label>YouTube Playlist URL or ID (Contains Free Movies)</label>
                    <input type="text" name="yt_playlist" value="<?= htmlspecialchars($currentUser['yt_playlist_id']) ?>" placeholder="e.g., PLxyz..." class="tv-focusable">
                    
                    <button type="submit" name="update_settings" class="btn-primary tv-focusable" style="margin-top:10px;">Save Settings</button>
                </form>
            </div>
            <?php endif; ?>

            <div class="admin-card">
                <h2>Change Profile Name</h2>
                <form method="POST" class="admin-form">
                    <?php if ($is_main_profile): ?>
                        <label>Select Profile</label>
                        <select name="target_profile_id" class="tv-focusable">
                            <?php
                            $stmt = $pdo->prepare("SELECT * FROM profiles WHERE user_id = ?");
                            $stmt->execute([$_SESSION['user_id']]);
                            foreach ($stmt->fetchAll() as $p) {
                                echo "<option value='{$p['id']}'>{$p['name']}</option>";
                            }
                            ?>
                        </select>
                    <?php else: ?>
                        <p style="color:var(--gray); margin-bottom:15px;">Update your profile name below.</p>
                        <input type="hidden" name="target_profile_id" value="<?= htmlspecialchars($_SESSION['profile_id']) ?>">
                    <?php endif; ?>
                    <label>New Name</label>
                    <input type="text" name="new_profile_name" required class="tv-focusable">
                    <button type="submit" name="rename_profile" class="btn-primary tv-focusable" style="margin-top:10px;">Update Name</button>
                </form>
            </div>

            <div class="admin-card">
                <h2>Change Profile Picture</h2>
                <form method="POST" enctype="multipart/form-data" class="admin-form">
                    <?php if ($is_main_profile): ?>
                        <label>Select Profile</label>
                        <select name="target_profile_id" class="tv-focusable">
                            <?php
                            $stmt = $pdo->prepare("SELECT * FROM profiles WHERE user_id = ?");
                            $stmt->execute([$_SESSION['user_id']]);
                            foreach ($stmt->fetchAll() as $p) {
                                echo "<option value='{$p['id']}'>{$p['name']}</option>";
                            }
                            ?>
                        </select>
                    <?php else: ?>
                        <p style="color:var(--gray); margin-bottom:15px;">Update your personal avatar picture below.</p>
                        <input type="hidden" name="target_profile_id" value="<?= htmlspecialchars($_SESSION['profile_id']) ?>">
                    <?php endif; ?>
                    
                    <label>Upload Avatar Image</label>
                    <input type="file" name="avatar_file" accept="image/*" class="tv-focusable">
                    <div style="display:flex; gap:10px; margin-top:10px; flex-wrap:wrap;">
                        <button type="submit" name="update_profile_pic" class="btn-primary tv-focusable">Update Picture</button>
                        <button type="submit" name="delete_profile_pic" class="btn-primary tv-focusable" style="background: #E50914;" onclick="return confirm('Are you sure you want to remove the avatar picture?');">Remove Picture</button>
                    </div>
                </form>
            </div>
            
            <div class="admin-card">
                <h2>Profile PIN Lock</h2>
                <form method="POST" class="admin-form">
                    <?php if ($is_main_profile): ?>
                        <p style="color:var(--gray); margin-bottom:15px;">Set or update a 4-digit PIN for any household account.</p>
                        <label>Select Profile</label>
                        <select name="target_profile_id" class="tv-focusable">
                            <?php
                            $stmt = $pdo->prepare("SELECT * FROM profiles WHERE user_id = ?");
                            $stmt->execute([$_SESSION['user_id']]);
                            foreach ($stmt->fetchAll() as $p) {
                                echo "<option value='{$p['id']}'>{$p['name']}</option>";
                            }
                            ?>
                        </select>
                    <?php else: ?>
                        <p style="color:var(--gray); margin-bottom:15px;">Set or update a 4-digit PIN for your personal profile.</p>
                        <input type="hidden" name="target_profile_id" value="<?= htmlspecialchars($_SESSION['profile_id']) ?>">
                    <?php endif; ?>
                    
                    <label>4-Digit PIN (Leave blank to remove PIN Lock)</label>
                    <input type="password" name="new_pin" maxlength="4" pattern="\d{4}" title="Please enter exactly 4 digits" class="tv-focusable" placeholder="e.g. 1234" inputmode="numeric">
                    <button type="submit" name="update_pin" class="btn-primary tv-focusable" style="margin-top:10px;">Save PIN</button>
                </form>
            </div>

            <?php if ($is_main_profile): ?>
            <div class="admin-card">
                <h2>Create Household Account</h2>
                <p style="color:var(--gray); margin-bottom:20px;">Add a new profile under your main account.</p>
                <form method="POST" enctype="multipart/form-data" class="admin-form">
                    <input type="hidden" name="admin_redirect" value="1">
                    <label>Profile Name</label>
                    <input type="text" name="profile_name" placeholder="Name" required class="tv-focusable">
                    <label>Upload Avatar Image (Optional)</label>
                    <input type="file" name="avatar_file" accept="image/*" class="tv-focusable">
                    <button type="submit" name="create_profile" class="btn-success tv-focusable" style="margin-top:10px;">Create Profile</button>
                </form>
            </div>

            <div class="admin-card">
                <h2>Change Admin Password</h2>
                <form method="POST" class="admin-form">
                    <label>New Password</label>
                    <input type="password" name="new_password" required class="tv-focusable">
                    <button type="submit" name="update_password" class="btn-primary tv-focusable" style="margin-top:10px;">Update Password</button>
                </form>
            </div>
            <?php endif; ?>

            <div class="admin-card">
                <h2>Delete Profile</h2>
                <form method="POST" class="admin-form">
                    <?php if ($is_main_profile): ?>
                        <p style="color:var(--gray); margin-bottom:15px;">Permanently delete a household account. (The main admin profile cannot be deleted).</p>
                        <label>Select Profile</label>
                        <select name="target_profile_id" class="tv-focusable">
                            <?php
                            // Exclude the main profile from the dropdown for safety
                            $stmt = $pdo->prepare("SELECT * FROM profiles WHERE user_id = ? AND id != ?");
                            $stmt->execute([$_SESSION['user_id'], $main_profile_id]);
                            $household_profiles = $stmt->fetchAll();
                            if (count($household_profiles) > 0) {
                                foreach ($household_profiles as $p) {
                                    echo "<option value='{$p['id']}'>{$p['name']}</option>";
                                }
                            } else {
                                echo "<option value='' disabled selected>No household accounts found</option>";
                            }
                            ?>
                        </select>
                        <button type="submit" name="delete_profile" class="btn-primary tv-focusable" style="background: #E50914; margin-top:10px;" onclick="return confirm('Are you sure you want to permanently delete this profile?');" <?= count($household_profiles) == 0 ? 'disabled' : '' ?>>Delete Profile</button>
                    <?php else: ?>
                        <p style="color:var(--gray); margin-bottom:15px;">Permanently delete your profile and all associated data.</p>
                        <input type="hidden" name="target_profile_id" value="<?= htmlspecialchars($_SESSION['profile_id']) ?>">
                        <button type="submit" name="delete_profile" class="btn-primary tv-focusable" style="background: #E50914; margin-top:10px;" onclick="return confirm('Are you sure you want to permanently delete your profile? This cannot be undone.');">Delete My Profile</button>
                    <?php endif; ?>
                </form>
            </div>

        <?php elseif($activeTab == 'library' && $is_main_profile): ?>
            <?php if(isset($_GET['synced'])) echo "<div style='background:green; padding:10px; margin-bottom:20px; border-radius:4px;'>Playlist Synced Successfully.</div>"; ?>
            <?php if(isset($_GET['edited'])) echo "<div style='background:green; padding:10px; margin-bottom:20px; border-radius:4px;'>Movie Updated Successfully.</div>"; ?>
            <?php if(isset($_GET['deleted'])) echo "<div style='background:#E50914; padding:10px; margin-bottom:20px; border-radius:4px;'>Movie Deleted.</div>"; ?>

            <div class="admin-card">
                <h2>Sync Database</h2>
                <p style="color:var(--gray); margin-bottom:20px;">Fetch videos from the playlist and auto-match with TMDB. (This may take a minute for large playlists)</p>
                <form method="POST">
                    <button type="submit" name="sync_playlist" class="btn-success tv-focusable" onclick="this.innerHTML='Syncing... Please wait.';">↺ Start Sync</button>
                </form>
            </div>

            <div class="admin-card">
                <h2>Manage Movies Library</h2>
                <p style="color:var(--gray); margin-bottom:10px;">Use the search bar below to seamlessly find specific movies.</p>
                
                <input type="text" id="adminSearch" onkeyup="filterAdminTable()" placeholder="🔍 Search movies by title or year..." class="tv-focusable">

                <div class="movies-grid" id="moviesGrid">
                    <?php
                    $mStmt = $pdo->query("SELECT * FROM movies ORDER BY id DESC");
                    while($m = $mStmt->fetch()):
                    ?>
                    <div class="movie-grid-item">
                        <img src="<?= htmlspecialchars($m['poster_path']) ?>" onerror="this.src='https://via.placeholder.com/300x450/222/fff?text=No+Poster'">
                        <div class="movie-grid-info">
                            <div class="movie-grid-title m-title" title="<?= htmlspecialchars($m['clean_title']) ?>"><?= htmlspecialchars($m['clean_title']) ?></div>
                            <div class="movie-grid-year m-year"><?= htmlspecialchars($m['release_year']) ?></div>
                            <div style="font-size:0.7em; color:grey; margin-bottom:10px;" class="m-raw"><?= htmlspecialchars($m['raw_title']) ?></div>
                            <div class="movie-grid-actions">
                                <button type="button" class="action-btn edit-btn tv-focusable" title="Edit Metadata" onclick='openEditModal(<?= htmlspecialchars(json_encode($m), ENT_QUOTES, 'UTF-8') ?>)'>
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" style="flex:1; display:flex;" onsubmit="return confirm('Delete this movie?');">
                                    <input type="hidden" name="movie_id" value="<?= $m['id'] ?>">
                                    <button type="submit" name="delete_movie" class="action-btn del-btn tv-focusable" title="Delete Movie">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
        <?php elseif($activeTab == 'shows' && $is_main_profile): ?>
            <?php if(isset($_GET['show_added'])) echo "<div style='background:green; padding:10px; margin-bottom:20px; border-radius:4px;'>Show Added Successfully.</div>"; ?>
            <?php if(isset($_GET['episodes_synced'])) echo "<div style='background:green; padding:10px; margin-bottom:20px; border-radius:4px;'>Episodes Synced Successfully.</div>"; ?>
            <?php if(isset($_GET['show_edited'])) echo "<div style='background:green; padding:10px; margin-bottom:20px; border-radius:4px;'>Show Updated Successfully.</div>"; ?>
            <?php if(isset($_GET['show_deleted'])) echo "<div style='background:#E50914; padding:10px; margin-bottom:20px; border-radius:4px;'>Show Deleted.</div>"; ?>

            <div class="admin-card">
                <h2>Add Show / Web Series</h2>
                <form method="POST" class="admin-form">
                    <label>YouTube Playlist URL or ID</label>
                    <input type="text" name="yt_playlist" required placeholder="e.g., PLxyz..." class="tv-focusable">
                    
                    <label>TMDB TV Show ID or Link (Optional but highly recommended)</label>
                    <input type="text" name="tmdb_id" placeholder="e.g., https://www.themoviedb.org/tv/12345" class="tv-focusable">
                    
                    <button type="submit" name="add_show" class="btn-success tv-focusable" style="margin-top:10px;">Add Show</button>
                </form>
            </div>

            <div class="admin-card">
                <?php if(isset($_GET['episodes_synced'])): ?>
                <div style="background: rgba(34, 197, 94, 0.2); color: #4ade80; border: 1px solid #16a34a; padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: bold;">
                    <i class="fas fa-check-circle"></i> Successfully fetched <?= intval($_GET['episodes_synced']) ?> new episodes!
                </div>
                <?php endif; ?>
                <?php if(isset($_GET['show_added'])): ?>
                <div style="background: rgba(34, 197, 94, 0.2); color: #4ade80; border: 1px solid #16a34a; padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: bold;">
                    <i class="fas fa-check-circle"></i> Successfully added show metadata! Click 'Fetch Episodes' to sync.
                </div>
                <?php endif; ?>
                <?php if(isset($_GET['sync_error'])): ?>
                <div style="background: rgba(220, 38, 38, 0.2); color: #f87171; border: 1px solid #dc2626; padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: bold;">
                    <i class="fas fa-exclamation-circle"></i> Error: <?= htmlspecialchars($_GET['sync_error']) ?>
                </div>
                <?php endif; ?>
                <h2>Manage Shows Library</h2>
                <input type="text" id="adminShowSearch" onkeyup="filterAdminShowsTable()" placeholder="🔍 Search shows by title or year..." class="tv-focusable" style="margin-bottom:20px; width:100%; padding:15px; background:#222; border:1px solid #444; color:white; border-radius:8px; font-size:1.1rem;">

                <div class="movies-grid" id="showsGrid">
                    <?php
                    $sStmt = $pdo->query("SELECT * FROM shows ORDER BY id DESC");
                    while($s = $sStmt->fetch()):
                    ?>
                    <div class="movie-grid-item" style="display:flex; flex-direction:column;">
                        <img src="<?= htmlspecialchars($s['poster_path']) ?>" onerror="this.src='https://via.placeholder.com/300x450/222/fff?text=No+Poster'">
                        <div class="movie-grid-info" style="display:flex; flex-direction:column; flex:1;">
                            <div class="movie-grid-title s-title" title="<?= htmlspecialchars($s['clean_title']) ?>"><?= htmlspecialchars($s['clean_title']) ?></div>
                            <div class="movie-grid-year s-year"><?= htmlspecialchars($s['release_year']) ?></div>
                            <div style="font-size:0.7em; color:grey; margin-bottom:10px;" class="s-raw"><?= htmlspecialchars($s['raw_title']) ?></div>
                            
                            <div style="display:flex; flex-direction:column; gap:8px; margin-top:auto;">
                                <form method="POST" onsubmit="this.querySelector('button').innerHTML='Syncing...';">
                                    <input type="hidden" name="show_id" value="<?= $s['id'] ?>">
                                    <button type="submit" name="sync_show_episodes" class="btn-primary tv-focusable" style="width:100%; padding:8px; font-size:0.95rem; margin-top:0;" title="Fetch Episodes">
                                        <i class="fas fa-sync-alt"></i> Fetch Episodes
                                    </button>
                                </form>
                                <div style="display:flex; gap:8px;">
                                    <button type="button" class="action-btn edit-btn tv-focusable" style="flex:1;" title="Edit Show" onclick='openShowEditModal(<?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>)'>
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="action-btn tv-focusable" style="flex:1; background:#444;" title="Manage Episodes" onclick="window.location.href='?p=admin&tab=episodes&show_id=<?= $s['id'] ?>'">
                                        <i class="fas fa-list"></i>
                                    </button>
                                    <form method="POST" style="flex:1; display:flex;" onsubmit="return confirm('Delete this show and ALL its episodes?');">
                                        <input type="hidden" name="show_id" value="<?= $s['id'] ?>">
                                        <button type="submit" name="delete_show" class="action-btn del-btn tv-focusable" title="Delete Show">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
            
        <?php elseif($activeTab == 'episodes' && $is_main_profile && isset($_GET['show_id'])): 
            $showId = (int)$_GET['show_id'];
            $shStmt = $pdo->prepare("SELECT clean_title FROM shows WHERE id = ?");
            $shStmt->execute([$showId]);
            $showName = $shStmt->fetchColumn();
        ?>
            <?php if(isset($_GET['ep_edited'])) echo "<div style='background:green; padding:10px; margin-bottom:20px; border-radius:4px;'>Episode Updated Successfully.</div>"; ?>
            <div class="admin-card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:2px solid #E50914; padding-bottom:5px;">
                    <h2 style="margin-bottom:0;">Manage Episodes: <?= htmlspecialchars($showName) ?></h2>
                    <button class="btn-primary tv-focusable" style="margin-top:0;" onclick="window.location.href='?p=admin&tab=shows'"><i class="fas fa-arrow-left"></i> Back to Shows</button>
                </div>
                <div style="display:flex; flex-direction:column; gap:15px;">
                    <?php
                    $eStmt = $pdo->prepare("SELECT * FROM episodes WHERE show_id = ? ORDER BY episode_number ASC");
                    $eStmt->execute([$showId]);
                    while($ep = $eStmt->fetch()):
                    ?>
                    <div style="display:flex; gap:15px; background:#222; padding:15px; border-radius:8px; border:1px solid #444; align-items:center;">
                        <img src="<?= htmlspecialchars($ep['thumbnail_path']) ?>" style="width:120px; aspect-ratio:16/9; object-fit:cover; border-radius:4px;" onerror="this.src='https://via.placeholder.com/120x68/222/fff?text=No+Thumb'">
                        <div style="flex:1;">
                            <div style="font-weight:bold; font-size:1.1rem;">E<?= $ep['episode_number'] ?>: <?= htmlspecialchars($ep['title']) ?></div>
                            <div style="color:#aaa; font-size:0.85rem; margin-top:5px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;"><?= htmlspecialchars($ep['description']) ?></div>
                        </div>
                        <button type="button" class="action-btn tv-focusable" style="padding:10px; background:#333; border-radius:4px;" onclick='openEpisodeEditModal(<?= htmlspecialchars(json_encode($ep), ENT_QUOTES, 'UTF-8') ?>)'><i class="fas fa-edit"></i> Edit</button>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Admin Edit Modal -->
    <div id="adminEditModal" class="modal-overlay">
        <div class="auth-box" style="max-width: 600px; padding: 40px; margin: auto; max-height: 90vh; overflow-y: auto;">
            <h2>Edit Metadata</h2>
            <form method="POST" class="admin-form" style="margin-top:10px;">
                <input type="hidden" name="movie_id" id="edit_id">
                
                <div style="background: rgba(229, 9, 20, 0.1); padding: 10px; border-radius: 4px; border: 1px solid #E50914; margin-bottom: 20px;">
                    <label style="color: white; margin-top: 0;">TMDB ID or Link (Force Sync & Overwrite)</label>
                    <p style="font-size: 0.8rem; color: #ccc; margin-bottom: 5px;">Paste a TMDB link (e.g., https://www.themoviedb.org/movie/12345) to instantly fetch the correct poster, description, and cast.</p>
                    <input type="text" name="tmdb_force_link" id="edit_tmdb_link" placeholder="Leave blank to use manual inputs below..." class="tv-focusable">
                </div>

                <label>Title</label>
                <input type="text" name="m_title" id="edit_title" class="tv-focusable">
                <label>Year</label>
                <input type="text" name="m_year" id="edit_year" class="tv-focusable">
                <label>Backdrop / Banner URL</label>
                <input type="text" name="m_backdrop" id="edit_backdrop" class="tv-focusable">
                <label>Description</label>
                <textarea name="m_desc" id="edit_desc" rows="4" class="tv-focusable"></textarea>
                
                <div style="display:flex; gap:10px; margin-top: 20px;">
                    <button type="submit" name="edit_movie" class="btn-success tv-focusable" style="margin-top:0;">Save Changes</button>
                    <button type="button" onclick="document.getElementById('adminEditModal').style.display='none'" class="btn-primary tv-focusable" style="margin-top:0; background:#444;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Admin Show Edit Modal -->
    <div id="adminShowEditModal" class="modal-overlay">
        <div class="auth-box" style="max-width: 600px; padding: 40px; margin: auto; max-height: 90vh; overflow-y: auto;">
            <h2>Edit Show Metadata</h2>
            <form method="POST" class="admin-form" style="margin-top:10px;">
                <input type="hidden" name="show_id" id="edit_s_id">
                <label>Title</label>
                <input type="text" name="s_title" id="edit_s_title" class="tv-focusable">
                <label>Year</label>
                <input type="text" name="s_year" id="edit_s_year" class="tv-focusable">
                <label>Poster URL</label>
                <input type="text" name="s_poster" id="edit_s_poster" class="tv-focusable">
                <label>Backdrop / Banner URL</label>
                <input type="text" name="s_backdrop" id="edit_s_backdrop" class="tv-focusable">
                <label>Description</label>
                <textarea name="s_desc" id="edit_s_desc" rows="4" class="tv-focusable"></textarea>
                
                <div style="display:flex; gap:10px; margin-top: 20px;">
                    <button type="submit" name="edit_show" class="btn-success tv-focusable" style="margin-top:0;">Save Changes</button>
                    <button type="button" onclick="document.getElementById('adminShowEditModal').style.display='none'" class="btn-primary tv-focusable" style="margin-top:0; background:#444;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Admin Episode Edit Modal -->
    <div id="adminEpisodeEditModal" class="modal-overlay">
        <div class="auth-box" style="max-width: 600px; padding: 40px; margin: auto; max-height: 90vh; overflow-y: auto;">
            <h2>Edit Episode Metadata</h2>
            <form method="POST" class="admin-form" style="margin-top:10px;">
                <input type="hidden" name="episode_id" id="edit_e_id">
                <input type="hidden" name="show_id" id="edit_e_show_id">
                <label>Episode Number</label>
                <input type="number" name="e_number" id="edit_e_number" class="tv-focusable" style="width: 100px;">
                <label>Title</label>
                <input type="text" name="e_title" id="edit_e_title" class="tv-focusable">
                <label>Thumbnail URL</label>
                <input type="text" name="e_thumbnail" id="edit_e_thumbnail" class="tv-focusable">
                <label>Description</label>
                <textarea name="e_desc" id="edit_e_desc" rows="4" class="tv-focusable"></textarea>
                
                <div style="display:flex; gap:10px; margin-top: 20px;">
                    <button type="submit" name="edit_episode" class="btn-success tv-focusable" style="margin-top:0;">Save Changes</button>
                    <button type="button" onclick="document.getElementById('adminEpisodeEditModal').style.display='none'" class="btn-primary tv-focusable" style="margin-top:0; background:#444;">Cancel</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- ==================== JAVASCRIPT LOGIC ==================== -->
<script>
    let lastFocusedElement = null; 
    
    // Global keyboard/mouse state for CSS DPAD isolation
    document.addEventListener('keydown', function(e) {
        if(e.key.startsWith('Arrow') || e.key === 'Enter') document.body.classList.add('is-keyboard');
    });
    document.addEventListener('mousedown', function() { document.body.classList.remove('is-keyboard'); });
    document.addEventListener('touchstart', function() { document.body.classList.remove('is-keyboard'); }, {passive: true});

    /* ==================== INITIAL TV FOCUS ==================== */
    function restoreFocus() {
        let lastMovieId = sessionStorage.getItem('lastMovieId');
        let lastSection = sessionStorage.getItem('lastSection');
        let lastSliderIdx = sessionStorage.getItem('lastSliderIdx');
        let focused = false;
        
        if (lastMovieId) {
            let target = null;
            let isHeroTarget = false;
            
            if (lastSection === 'hero') {
                target = document.querySelector(`.hero-carousel .tv-focusable[data-movie-id="${lastMovieId}"]`);
                if (target) {
                    isHeroTarget = true;
                    let slide = target.closest('.hero-slide');
                    if (slide && !slide.classList.contains('active')) {
                        let activeSlide = document.querySelector('.hero-slide.active');
                        if (activeSlide) activeSlide.classList.remove('active');
                        slide.classList.add('active');
                    }
                }
            } else if (lastSection === 'search') {
                // If we specifically came from search recommendations, look in the results grid
                let grid = document.getElementById('searchResultsGrid');
                if (grid) {
                    target = grid.querySelector(`.tv-focusable[data-movie-id="${lastMovieId}"]`);
                }
            } else if (lastSection) {
                let wrapper = document.getElementById(lastSection) || document.querySelector(`[data-section="${lastSection}"]`);
                if (wrapper) {
                    target = wrapper.querySelector(`.tv-focusable[data-movie-id="${lastMovieId}"]`);
                }
            }
            
            // Fallback: only use global search if no specific section was provided 
            // OR if it's an old index-based restoration
            if (!target && !lastSection && lastSliderIdx && lastSliderIdx !== '-1') {
                let wrappers = document.querySelectorAll('.slider-wrapper');
                let targetWrapper = wrappers[lastSliderIdx];
                if (targetWrapper) {
                    target = targetWrapper.querySelector(`.tv-focusable[data-movie-id="${lastMovieId}"]`);
                }
            }
            
            if (!target && !lastSection) {
                let sliderEls = document.querySelectorAll(`.slider-wrapper .tv-focusable[data-movie-id="${lastMovieId}"]`);
                target = Array.from(sliderEls).find(el => el.offsetParent !== null) || sliderEls[0];
            }
            
            if (target) {
                target.focus({ preventScroll: true });
                if (isHeroTarget) {
                    window.scrollTo({ top: 0, behavior: 'auto' });
                } else {
                    target.scrollIntoView({behavior: "auto", block: "center"});
                }
                focused = true;
            }

            if (focused) {
                sessionStorage.removeItem('lastMovieId');
                sessionStorage.removeItem('lastSection');
                sessionStorage.removeItem('lastSliderIdx');
            }
        }
        
        if (!focused && document.activeElement === document.body) {
            const firstFocusable = Array.from(document.querySelectorAll('.tv-focusable:not([disabled])')).find(el => {
                let style = window.getComputedStyle(el);
                return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length) && 
                       style.visibility !== 'hidden' && style.opacity !== '0' && style.display !== 'none';
            });
            if (firstFocusable) {
                firstFocusable.focus();
            }
        }
    }

    window.addEventListener('DOMContentLoaded', () => {
        initHeroCarousel(); 
        setTimeout(restoreFocus, 150); 
    });

    // Handle bfcache or back button restorations
    window.addEventListener('pageshow', (e) => {
        if (e.persisted) {
            setTimeout(restoreFocus, 50);
        }
    });

    function scrollSlider(btn, direction) {
        let slider = btn.closest('.slider-wrapper').querySelector('.slider');
        let scrollAmount = slider.offsetWidth * 0.8;
        slider.scrollBy({ left: scrollAmount * direction, behavior: 'smooth' });
    }

    window.addEventListener('scroll', () => {
        const nav = document.getElementById('navbar');
        if(nav) {
            if (window.scrollY > 50) nav.classList.add('scrolled');
            else nav.classList.remove('scrolled');
        }
    });

    let heroInterval = null;
    function initHeroCarousel() {
        if (heroInterval) clearInterval(heroInterval);
        const heroSlides = document.querySelectorAll('.hero-slide');
        if (heroSlides.length > 1) {
            let currentHero = 0;
            heroInterval = setInterval(() => {
                let isHeroFocused = document.activeElement && document.activeElement.closest('.hero-slide');
                if (!isHeroFocused) {
                    heroSlides[currentHero].classList.remove('active');
                    currentHero = (currentHero + 1) % heroSlides.length;
                    heroSlides[currentHero].classList.add('active');
                }
            }, 15000);
        }
    }

    // Dropdown toggle logic removed

    /* ==================== SEARCH PAGE LOGIC ==================== */
    const allSearchMoviesData = <?php echo isset($allSearchMovies) ? json_encode($allSearchMovies) : '[]'; ?>;
    let activeGenreFilter = null;
    let lastSearchQuery = "";

    function initSearchPage() {
        const vk = document.getElementById('virtualKeyboard');
        const input = document.getElementById('searchInput');
        if (!vk || !input) return;

        // Virtual keyboard click handler
        vk.addEventListener('click', function(e) {
            let key = e.target.closest('.vk-key');
            if (!key) return;

            if (key.dataset.char) {
                input.value += key.dataset.char;
            } else if (key.dataset.action === 'space') {
                input.value += ' ';
            } else if (key.dataset.action === 'backspace') {
                input.value = input.value.slice(0, -1);
            } else if (key.dataset.action === 'clear') {
                input.value = '';
            }
            performSearch();
        });

        // Also allow physical keyboard typing if input is focused
        input.addEventListener('input', function() {
            performSearch();
        });

        // Genre filter click handler
        const genreList = document.getElementById('genreList');
        if (genreList) {
            genreList.addEventListener('click', function(e) {
                let item = e.target.closest('.genre-item');
                if (!item) return;
                let genre = item.dataset.genre;
                
                // Toggle active genre
                if (activeGenreFilter === genre) {
                    activeGenreFilter = null;
                    item.classList.remove('active');
                } else {
                    document.querySelectorAll('.genre-item.active').forEach(el => el.classList.remove('active'));
                    activeGenreFilter = genre;
                    item.classList.add('active');
                }
                
                // Clear search input when selecting genre
                input.value = '';
                performSearch();
            });
        }
    }

    function performSearch() {
        const input = document.getElementById('searchInput');
        const grid = document.getElementById('searchResultsGrid');
        const title = document.getElementById('searchResultsTitle');
        if (!input || !grid || !title) return;

        let query = input.value.trim().toLowerCase();
        
        // Fix for shuffle bug: Don't reshuffle if query is empty and was already empty
        if (query === "" && lastSearchQuery === "" && !activeGenreFilter && title.textContent === 'Your Search Recommendations') {
            return;
        }
        lastSearchQuery = query;

        let results = [];

        if (query.length > 0) {
            // Text search mode
            activeGenreFilter = null;
            document.querySelectorAll('.genre-item.active').forEach(el => el.classList.remove('active'));
            
            results = allSearchMoviesData.filter(m => {
                let searchable = (m.clean_title || '').toLowerCase() + ' ' + (m.raw_title || '').toLowerCase() + ' ' + (m.genre || '').toLowerCase();
                return searchable.includes(query);
            });
            title.textContent = results.length > 0 ? `Results for "${input.value}"` : `No results for "${input.value}"`;
        } else if (activeGenreFilter) {
            // Genre filter mode
            results = allSearchMoviesData.filter(m => {
                return m.genre && m.genre.toLowerCase().includes(activeGenreFilter.toLowerCase());
            });
            title.textContent = activeGenreFilter;
        } else {
            // Default: show recommendations (already rendered server-side)
            title.textContent = 'Your Search Recommendations';
            // Rebuild the default recommendations grid
            let recs = allSearchMoviesData.filter(m => {
                let cwIds = <?php echo isset($cwExcludeIds) ? json_encode(array_map('intval', $cwExcludeIds)) : '[]'; ?>;
                return !cwIds.includes(parseInt(m.id));
            });
            // Shuffle
            for (let i = recs.length - 1; i > 0; i--) {
                let j = Math.floor(Math.random() * (i + 1));
                [recs[i], recs[j]] = [recs[j], recs[i]];
            }
            results = recs.slice(0, 20);
        }

        renderSearchGrid(grid, results);
    }

    function renderSearchGrid(grid, movies) {
        if (movies.length === 0) {
            grid.innerHTML = '<div class="search-no-results">No movies found. Try a different search.</div>';
            return;
        }

        grid.innerHTML = movies.map(m => {
            let imgSrc = m.poster_path || m.backdrop_path || 'https://via.placeholder.com/300x450/222/fff?text=No+Poster';
            return `<div class="search-card tv-focusable" tabindex="0" data-movie-id="${m.id}" onclick="sessionStorage.setItem('lastMovieId', '${m.id}'); sessionStorage.setItem('lastSection', 'search'); window.location.href='?p=movie&id=${m.id}'"> 
                <img src="${imgSrc}" alt="${(m.clean_title || '').replace(/"/g, '&quot;')}" onerror="this.src='https://via.placeholder.com/300x450/222/fff?text=No+Poster'">
                <div class="search-card-overlay">
                    <div class="search-card-title">${m.clean_title || m.raw_title || 'Untitled'}</div>
                </div>
            </div>`;
        }).join('');
    }

    // Initialize search page on DOMContentLoaded
    window.addEventListener('DOMContentLoaded', () => {
        initSearchPage();
    });

    let currentMovie = null;
    let player = null;
    let playerInterval = null;
    let progressInterval = null;
    let currentPlayerVideoId = null;
    let currentDbMovieId = null;
    let currentDbType = 'movie'; 
    let showRemainingTime = false; 
    
    let watchlistIds = <?php echo isset($watchlist) ? json_encode(array_map('intval', array_column($watchlist, 'id'))) : '[]'; ?>;
    let progressMap = <?php echo isset($progressMap) ? json_encode($progressMap) : '{}'; ?>;

    function toggleTimeDisplay() {
        showRemainingTime = !showRemainingTime;
        updateProgressBar();
        wakeUpPlayerControls();
    }

    const pinBoxes = [
        document.getElementById('pinBox1'),
        document.getElementById('pinBox2'),
        document.getElementById('pinBox3'),
        document.getElementById('pinBox4')
    ];

    if (pinBoxes[0]) {
        pinBoxes.forEach((box, index) => {
            box.addEventListener('input', (e) => {
                box.value = box.value.replace(/[^0-9]/g, ''); 
                if (box.value.length === 1) {
                    if (index < 3) {
                        pinBoxes[index + 1].focus();
                    } else {
                        submitPin(); 
                    }
                }
            });

            box.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && box.value === '') {
                    if (index > 0) {
                        pinBoxes[index - 1].focus();
                        pinBoxes[index - 1].value = ''; 
                    }
                }
            });
        });
    }

    function submitPin() {
        let pin = pinBoxes.map(b => b.value).join('');
        if(pin.length === 4) {
            document.getElementById('hiddenPinInput').value = pin;
            document.getElementById('pinForm').submit();
        }
    }

    function clearPinBoxes() {
        if(pinBoxes[0]) {
            pinBoxes.forEach(b => b.value = '');
        }
    }
    
    function handleProfileClick(id, hasPin) {
        if (hasPin) {
            document.getElementById('loginProfileId').value = id;
            clearPinBoxes();
            document.getElementById('pinLockModal').style.display = 'flex';
            setTimeout(() => {
                if(pinBoxes[0]) pinBoxes[0].focus();
            }, 100);
        } else {
            window.location.href = '?select_profile=' + id;
        }
    }
    
    function closePinModal() {
        document.getElementById('pinLockModal').style.display = 'none';
        clearPinBoxes();
    }

    function refreshHomeContent() {
        if (!document.getElementById('home-content')) return;
        fetch('?p=home&ajax=get_home_data')
        .then(res => res.json())
        .then(data => {
            document.getElementById('home-content').innerHTML = data.html;
            watchlistIds = data.watchlistIds;
            progressMap = data.progressMap;
            
            initHeroCarousel();
        }).catch(e => console.error('Error syncing dynamic content:', e));
    }

    function toggleMoviePageWatchlist(movieId) {
        const btn = document.getElementById('moviePageWatchlistBtn');
        if (!btn) return;
        const formData = new FormData();
        formData.append('movie_id', movieId);

        fetch('?ajax=toggle_watchlist', {
            method: 'POST',
            body: formData
        }).then(res => res.json())
          .then(data => {
              if(data.status === 'added') {
                  btn.innerHTML = '<i class="fas fa-check"></i>';
              } else {
                  btn.innerHTML = '<i class="fas fa-plus"></i>';
              }
          });
    }

    function toggleShowPageWatchlist(showId) {
        const btn = document.getElementById('showPageWatchlistBtn');
        if (!btn) return;
        const formData = new FormData();
        formData.append('show_id', showId);

        fetch('?ajax=toggle_show_watchlist', {
            method: 'POST',
            body: formData
        }).then(res => res.json())
          .then(data => {
              if(data.status === 'added') {
                  btn.innerHTML = '<i class="fas fa-check"></i>';
              } else {
                  btn.innerHTML = '<i class="fas fa-plus"></i>';
              }
          });
    }

    function openEditModal(movie) {
        document.getElementById('edit_id').value = movie.id;
        document.getElementById('edit_tmdb_link').value = ''; 
        document.getElementById('edit_title').value = movie.clean_title;
        document.getElementById('edit_year').value = movie.release_year;
        document.getElementById('edit_backdrop').value = movie.backdrop_path;
        document.getElementById('edit_desc').value = movie.description;
        document.getElementById('adminEditModal').style.display = 'flex';
    }

    function filterAdminTable() {
        let input = document.getElementById("adminSearch");
        let filter = input.value.toUpperCase();
        let grid = document.getElementById("moviesGrid");
        let items = grid.getElementsByClassName("movie-grid-item");

        for (let i = 0; i < items.length; i++) {
            let title = items[i].getElementsByClassName("m-title")[0];
            let raw = items[i].getElementsByClassName("m-raw")[0];
            let year = items[i].getElementsByClassName("m-year")[0];
            if (title || raw || year) {
                let txtValue = title.textContent + " " + raw.textContent + " " + year.textContent;
                if (txtValue.toUpperCase().indexOf(filter) > -1) {
                    items[i].style.display = "flex";
                } else {
                    items[i].style.display = "none";
                }
            }       
        }
    }

    function openShowEditModal(show) {
        document.getElementById('edit_s_id').value = show.id;
        document.getElementById('edit_s_title').value = show.clean_title;
        document.getElementById('edit_s_year').value = show.release_year;
        document.getElementById('edit_s_poster').value = show.poster_path;
        document.getElementById('edit_s_backdrop').value = show.backdrop_path;
        document.getElementById('edit_s_desc').value = show.description;
        document.getElementById('adminShowEditModal').style.display = 'flex';
    }

    function openEpisodeEditModal(ep) {
        document.getElementById('edit_e_id').value = ep.id;
        document.getElementById('edit_e_show_id').value = ep.show_id;
        document.getElementById('edit_e_number').value = ep.episode_number;
        document.getElementById('edit_e_title').value = ep.title;
        document.getElementById('edit_e_thumbnail').value = ep.thumbnail_path;
        document.getElementById('edit_e_desc').value = ep.description;
        document.getElementById('adminEpisodeEditModal').style.display = 'flex';
    }

    function filterAdminShowsTable() {
        let input = document.getElementById("adminShowSearch");
        let filter = input.value.toUpperCase();
        let grid = document.getElementById("showsGrid");
        if(!grid) return;
        let items = grid.getElementsByClassName("movie-grid-item");

        for (let i = 0; i < items.length; i++) {
            let title = items[i].getElementsByClassName("s-title")[0];
            let raw = items[i].getElementsByClassName("s-raw")[0];
            let year = items[i].getElementsByClassName("s-year")[0];
            if (title || raw || year) {
                let txtValue = title.textContent + " " + raw.textContent + " " + year.textContent;
                if (txtValue.toUpperCase().indexOf(filter) > -1) {
                    items[i].style.display = "flex";
                } else {
                    items[i].style.display = "none";
                }
            }       
        }
    }

    /* ==================== YOUTUBE PLAYER LOGIC ==================== */
    var tag = document.createElement('script');
    tag.src = "https://www.youtube.com/iframe_api";
    var firstScriptTag = document.getElementsByTagName('script')[0];
    firstScriptTag.parentNode.insertBefore(tag, firstScriptTag);

    function onYouTubeIframeAPIReady() {}

    function playNextEpisode() {
        if (currentDbType === 'episode' && typeof showEpisodes !== 'undefined') {
            let currentIndex = showEpisodes.findIndex(e => e.id === currentDbMovieId);
            if (currentIndex !== -1 && currentIndex < showEpisodes.length - 1) {
                let nextEp = showEpisodes[currentIndex + 1];
                saveProgress().then(() => {
                    let epProg = progressMap[nextEp.id];
                    let startAt = epProg ? epProg.time : 0;
                    openPlayer(nextEp.yt_video_id, nextEp.title, startAt, nextEp.id, 'episode');
                });
            }
        }
    }

    function playPreviousEpisode() {
        if (currentDbType === 'episode' && typeof showEpisodes !== 'undefined') {
            let currentIndex = showEpisodes.findIndex(e => e.id === currentDbMovieId);
            if (currentIndex > 0) {
                let prevEp = showEpisodes[currentIndex - 1];
                saveProgress().then(() => {
                    let epProg = progressMap[prevEp.id];
                    let startAt = epProg ? epProg.time : 0;
                    openPlayer(prevEp.yt_video_id, prevEp.title, startAt, prevEp.id, 'episode');
                });
            }
        }
    }

    function openPlayer(videoId, title, startAtTime = 0, dbMovieId = null, dbMediaType = 'movie') {
        currentPlayerVideoId = videoId;
        currentDbMovieId = dbMovieId;
        currentDbType = dbMediaType;
        document.getElementById('playerContainer').style.display = 'block';
        
        let prevBtn = document.getElementById('prevEpBtn');
        let nextBtn = document.getElementById('nextEpBtn');
        if (prevBtn && nextBtn) {
            if (dbMediaType === 'episode' && typeof showEpisodes !== 'undefined') {
                prevBtn.style.display = 'block';
                nextBtn.style.display = 'block';
            } else {
                prevBtn.style.display = 'none';
                nextBtn.style.display = 'none';
            }
        }
        
        wakeUpPlayerControls();
        
        document.getElementById('playerTitle').innerText = title;
        document.body.style.overflow = 'hidden';
        
        document.getElementById('progressFilled').style.width = '0%';
        document.getElementById('timeDisplay').innerText = '0:00 / 0:00';
        document.getElementById('playerEndTime').innerText = '';

        if (player) {
            player.destroy();
            player = null;
        }
        
        player = new YT.Player('ytplayer', {
            height: '100%',
            width: '100%',
            videoId: videoId,
            playerVars: {
                'autoplay': 1,
                'controls': 0, 
                'disablekb': 1, 
                'fs': 0, 
                'modestbranding': 1,
                'rel': 0,
                'playsinline': 1,
                'start': startAtTime,
                'cc_load_policy': 1, 
                'hl': 'en',
                'iv_load_policy': 3
            },
            events: {
                'onReady': onPlayerReady,
                'onStateChange': onPlayerStateChange
            }
        });
    }

    function saveProgress() {
        if (player && currentDbMovieId && player.getCurrentTime) {
            let time = Math.floor(player.getCurrentTime());
            let duration = player.getDuration();
            
            if (duration > 0 && time > duration - 10) {
                time = 0;
            }
            
            progressMap[currentDbMovieId] = { time: time, duration: Math.floor(duration) };
            
            if (currentDbType === 'movie' && currentMovie && parseInt(currentMovie.id) === currentDbMovieId && document.getElementById('movieModal') && document.getElementById('movieModal').style.display === 'flex') {
                let playBtn = document.getElementById('modalPlayBtn');
                if (playBtn) {
                    let playText = time > 0 ? 'Resume' : 'Play';
                    playBtn.innerHTML = `<i class="fas fa-play" style="margin-right:5px;"></i> ${playText}`;
                    playBtn.onclick = () => openPlayer(currentMovie.yt_video_id, currentMovie.clean_title, time, currentDbMovieId);
                }
            }
            
            const formData = new FormData();
            formData.append(currentDbType === 'episode' ? 'episode_id' : 'movie_id', currentDbMovieId);
            formData.append('time', time);
            formData.append('duration', Math.floor(duration));
            
            let endpoint = currentDbType === 'episode' ? '?ajax=save_episode_progress' : '?ajax=save_progress';
            return fetch(endpoint, { method: 'POST', body: formData }).catch(e => console.warn(e));
        }
        return Promise.resolve();
    }

    function closePlayer() {
        document.getElementById('playerContainer').style.display = 'none';
        document.body.style.overflow = 'auto';
        
        if (document.fullscreenElement) {
            document.exitFullscreen().catch(err => console.log(err));
        }
        
        saveProgress().then(() => {
            refreshHomeContent();
        });
        
        clearInterval(playerInterval);
        if(progressInterval) clearInterval(progressInterval);
        
        if(player) {
            player.pauseVideo();
            player.destroy(); 
            player = null;
        }
        
        currentSpeedIdx = 1;
        if(document.getElementById('speedBtn')) document.getElementById('speedBtn').innerText = '1x';
        
        document.getElementById('progressFilled').style.width = '0%';
        document.getElementById('timeDisplay').innerText = '0:00 / 0:00';
        document.getElementById('playerEndTime').innerText = '';
        
        let menu = document.getElementById('playerSettingsMenu');
        if(menu) menu.classList.remove('show');
        
        let ccMenu = document.getElementById('universalPlayerMenu');
        if(ccMenu) {
            ccMenu.style.display = 'none';
            ccMenu.dataset.menuType = '';
        }
    }

    function onPlayerReady(event) {
        event.target.playVideo();
        if(playerInterval) clearInterval(playerInterval);
        playerInterval = setInterval(updateProgressBar, 500);
        
        if(progressInterval) clearInterval(progressInterval);
        progressInterval = setInterval(() => { saveProgress(); }, 5000); 
    }

    function onPlayerStateChange(event) {
        const btn = document.getElementById('playPauseBtn');
        if (event.data == YT.PlayerState.PLAYING) {
            btn.innerHTML = '<i class="fas fa-pause"></i>'; 
        } else {
            btn.innerHTML = '<i class="fas fa-play"></i>'; 
        }
    }

    function togglePlay() {
        if(player && player.getPlayerState() == YT.PlayerState.PLAYING){
            player.pauseVideo();
            wakeUpPlayerControls();
        } else if(player) {
            player.playVideo();
            wakeUpPlayerControls();
        }
    }

    function skip(amount) {
        if(player && player.getCurrentTime) {
            let current = player.getCurrentTime();
            player.seekTo(current + amount, true);
            wakeUpPlayerControls();
        }
    }

    function toggleCCMenu() {
        let menu = document.getElementById('universalPlayerMenu');
        if (menu.style.display === 'flex' && menu.dataset.menuType === 'cc') {
            menu.style.display = 'none';
            menu.dataset.menuType = '';
            return;
        }
        
        menu.dataset.menuType = 'cc';
        menu.innerHTML = '<div style="color:white; font-weight:bold; margin-bottom:5px;">Subtitles / CC</div>';
        
        // Ensure 'Off' button is ALWAYS appended first so users can disable auto-generated subs
        let offBtn = document.createElement('button');
        offBtn.className = 'tv-focusable player-btn';
        offBtn.style.justifyContent = 'flex-start';
        offBtn.style.width = '100%';
        offBtn.innerText = 'Off';
        offBtn.onclick = () => {
            player.unloadModule('captions');
            player.setOption('captions', 'track', {}); 
            document.getElementById('ccBtn').classList.remove('cc-active');
            menu.style.display = 'none';
        };
        menu.appendChild(offBtn);
        
        menu.style.display = 'flex';
        player.loadModule('captions');
        
        setTimeout(() => {
            let tracks = player.getOption('captions', 'tracklist') || [];
            
            if (tracks.length === 0) {
                let note = document.createElement('div');
                note.style = "color:gray; padding:5px; font-size: 0.9rem;";
                note.innerText = "(Auto-generated / Default)";
                menu.appendChild(note);
            } else {
                tracks.forEach(track => {
                    let btn = document.createElement('button');
                    btn.className = 'tv-focusable player-btn';
                    btn.style.justifyContent = 'flex-start';
                    btn.style.width = '100%';
                    btn.innerText = track.languageName || track.name || track.languageCode;
                    btn.onclick = () => {
                        player.setOption('captions', 'track', {languageCode: track.languageCode});
                        document.getElementById('ccBtn').classList.add('cc-active');
                        menu.style.display = 'none';
                    };
                    menu.appendChild(btn);
                });
            }
            
            setTimeout(() => {
                let firstBtn = menu.querySelector('button');
                if(firstBtn) firstBtn.focus();
            }, 50);
        }, 500); 
    }

    const speeds = [0.5, 1, 1.25, 1.5, 2];
    let currentSpeedIdx = 1; 
    function toggleSpeed() {
        if (!player) return;
        currentSpeedIdx = (currentSpeedIdx + 1) % speeds.length;
        let speed = speeds[currentSpeedIdx];
        player.setPlaybackRate(speed);
        
        if(document.getElementById('speedBtn')) document.getElementById('speedBtn').innerText = speed + 'x';
    }

    function toggleFullscreen() {
        let elem = document.getElementById("playerContainer");
        if (!document.fullscreenElement) {
            elem.requestFullscreen().catch(err => {
                console.warn(`Fullscreen API Error`);
            });
        } else {
            document.exitFullscreen();
        }
    }

    function updateProgressBar() {
        if(player && player.getCurrentTime && player.getDuration) {
            let current = player.getCurrentTime();
            let duration = player.getDuration();
            if(duration > 0) {
                let percent = (current / duration) * 100;
                document.getElementById('progressFilled').style.width = percent + '%';
                
                let displayCurrentStr = showRemainingTime ? '-' + formatTime(duration - current) : formatTime(current);
                document.getElementById('timeDisplay').innerText = displayCurrentStr + ' / ' + formatTime(duration);
                
                let speed = player.getPlaybackRate ? player.getPlaybackRate() : 1;
                if (speed <= 0) speed = 1; 
                let remainingRealSeconds = (duration - current) / speed;
                let endDate = new Date(Date.now() + remainingRealSeconds * 1000);
                
                let endHours = endDate.getHours();
                let endMins = endDate.getMinutes();
                let ampm = endHours >= 12 ? 'PM' : 'AM';
                endHours = endHours % 12;
                endHours = endHours ? endHours : 12; 
                endMins = endMins < 10 ? '0' + endMins : endMins;
                
                document.getElementById('playerEndTime').innerText = `Ends at ${endHours}:${endMins} ${ampm}`;
            }
        }
    }

    function seekVideo(e) {
        if(!player) return;
        let bar = document.getElementById('progressBar');
        let rect = bar.getBoundingClientRect();
        let pos = (e.clientX - rect.left) / bar.offsetWidth;
        player.seekTo(pos * player.getDuration(), true);
        wakeUpPlayerControls();
    }

    function formatTime(seconds) {
        let h = Math.floor(seconds / 3600);
        let m = Math.floor((seconds % 3600) / 60);
        let s = Math.floor(seconds % 60);
        s = s < 10 ? "0" + s : s;
        if (h > 0) {
            m = m < 10 ? "0" + m : m;
            return h + ":" + m + ":" + s;
        }
        return m + ":" + s;
    }

    let timeout;
    function wakeUpPlayerControls() {
        let container = document.getElementById('playerContainer');
        if(!container.classList.contains('active')) {
            container.classList.add('active');
        }
        clearTimeout(timeout);
        
        timeout = setTimeout(() => {
            if(player && player.getPlayerState() == YT.PlayerState.PLAYING) {
                container.classList.remove('active');
                
                let menu = document.getElementById('playerSettingsMenu');
                if(menu) menu.classList.remove('show');
            }
        }, 4000);
    }

    const playerContainerEl = document.getElementById('playerContainer');
    if (playerContainerEl) {
        playerContainerEl.addEventListener('mousemove', wakeUpPlayerControls);
        playerContainerEl.addEventListener('touchstart', wakeUpPlayerControls, {passive: true});
        playerContainerEl.addEventListener('click', wakeUpPlayerControls);
    }

    /* ==================== TV/DPAD NAVIGATION ENGINE ==================== */
    window.addEventListener('keydown', function(e) {
        let pinModal = document.getElementById('pinLockModal');

        // Search page: physical keyboard passthrough for typing
        let searchInput = document.getElementById('searchInput');
        if (searchInput) {
            let playerContainer = document.getElementById('playerContainer');
            let movieModal = document.getElementById('movieModal');
            let isPlayerOpen = playerContainer && playerContainer.style.display === 'block';
            let isModalOpen = movieModal && movieModal.style.display === 'flex';
            
            if (!isPlayerOpen && !isModalOpen) {
                if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
                    // Single character key — type it into search
                    searchInput.value += e.key.toLowerCase();
                    performSearch();
                    e.preventDefault();
                    return;
                } else if (e.key === 'Backspace') {
                    searchInput.value = searchInput.value.slice(0, -1);
                    performSearch();
                    e.preventDefault();
                    return;
                }
            }
        }

        const validKeys = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Enter', 'Escape'];
        if (!validKeys.includes(e.key)) return;

        let playerContainer = document.getElementById('playerContainer');
        let movieModal = document.getElementById('movieModal');
        let addProfileModal = document.getElementById('addProfileModal');
        let adminEditModal = document.getElementById('adminEditModal');

        if (playerContainer && playerContainer.style.display === 'block') {
            wakeUpPlayerControls();
        }

        if (e.key === 'Escape') {
            var universalMenu = document.getElementById('universalPlayerMenu');
            if (universalMenu && universalMenu.style.display === 'flex') {
                universalMenu.style.display = 'none';
                universalMenu.dataset.menuType = '';
                return;
            }
            
            if (playerContainer && playerContainer.style.display === 'block') closePlayer();
            else if (movieModal && movieModal.style.display === 'flex') closeModal();
            else if (addProfileModal && addProfileModal.style.display === 'flex') {
                addProfileModal.style.display = 'none';
            }
            else if (adminEditModal && adminEditModal.style.display === 'flex') {
                adminEditModal.style.display = 'none';
            }
            else if (pinModal && pinModal.style.display === 'flex') {
                closePinModal();
            }
            
            var dropdowns = document.getElementsByClassName("dropdown-content");
            for (var i = 0; i < dropdowns.length; i++) {
                dropdowns[i].classList.remove('show');
            }
            
            return;
        }

        let activeScope = document;
        let isPlayerScope = false;
        
        if (playerContainer && playerContainer.style.display === 'block') {
            activeScope = playerContainer;
            isPlayerScope = true;
        } else if (movieModal && movieModal.style.display === 'flex') {
            activeScope = movieModal;
        } else if (addProfileModal && addProfileModal.style.display === 'flex') {
            activeScope = addProfileModal;
        } else if (adminEditModal && adminEditModal.style.display === 'flex') {
            activeScope = adminEditModal;
        } else if (pinModal && pinModal.style.display === 'flex') {
            activeScope = pinModal;
        }

        const focusables = Array.from(activeScope.querySelectorAll('.tv-focusable:not([disabled])')).filter(el => {
            let style = window.getComputedStyle(el);
            let isVisible = !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length) && style.visibility !== 'hidden' && style.opacity !== '0' && style.display !== 'none';
            
            if (isPlayerScope) {
                let pContainer = document.getElementById('playerContainer');
                if (pContainer && !pContainer.classList.contains('active')) isVisible = false;
            }

            if (el.closest('.dropdown-content') && !el.closest('.dropdown-content').classList.contains('show')) {
                isVisible = false;
            }
            
            if (el.closest('.player-settings-menu') && !el.closest('.player-settings-menu').classList.contains('show')) {
                isVisible = false;
            }
            
            if (el.closest('#universalPlayerMenu') && document.getElementById('universalPlayerMenu').style.display !== 'flex') {
                isVisible = false;
            }
            
            let heroSlide = el.closest('.hero-slide');
            if (heroSlide && !heroSlide.classList.contains('active')) {
                isVisible = false; 
            }

            return isVisible;
        });
        
        if (!focusables.length) return;

        let current = document.activeElement;
        
        if (!focusables.includes(current)) {
            if (e.key !== 'Enter') focusables[0].focus();
            return;
        }

        if (e.key === 'Enter') {
            if (current.tagName === 'INPUT' && current.type !== 'button' && current.type !== 'submit' && current.type !== 'file') {
                let form = current.closest('form');
                if (form) {
                    let submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
                    if (submitBtn) submitBtn.click();
                    else form.submit();
                }
            } else {
                current.click();
            }
            e.preventDefault();
            return;
        }
        
        if (isPlayerScope) {
            if (e.key === 'ArrowUp' && current.closest('.player-bottom')) {
                let backBtn = activeScope.querySelector('.player-header .tv-focusable');
                if (backBtn) {
                    backBtn.focus();
                    e.preventDefault();
                    return;
                }
            }
            if (e.key === 'ArrowDown' && current.closest('.player-header')) {
                let targetBtn = activeScope.querySelector('#playPauseBtn') || activeScope.querySelector('.player-bottom .tv-focusable');
                if (targetBtn) {
                    targetBtn.focus();
                    e.preventDefault();
                    return;
                }
            }
        }

        if (current.closest('nav') && e.key === 'ArrowDown') {
            let playBtn = document.querySelector('.hero-slide.active .btn-play');
            if (playBtn) {
                playBtn.focus();
                window.scrollTo({ top: 0, behavior: 'smooth' }); 
                e.preventDefault();
                return;
            }
        }
        
        if (current.closest('.slider-wrapper') && e.key === 'ArrowUp') {
            let allWrappers = document.querySelectorAll('.slider-wrapper');
            if (allWrappers.length > 0 && current.closest('.slider-wrapper') === allWrappers[0]) {
                let playBtn = document.querySelector('.hero-slide.active .btn-play');
                if (playBtn) {
                    playBtn.focus();
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    e.preventDefault();
                    return;
                }
            }
        }

        if (current.closest('.modal-content') && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
            if (current.classList.contains('modal-desc')) {
                // allow native scroll inside text description
            } else {
               return; 
            }
        }

        if (current.closest('.auth-box') && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
            // Allows normal up/down math, but we don't return early unless needed
        } else if (current.closest('.modal-content') && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
            if (!current.classList.contains('modal-desc')) return;
        }

        e.preventDefault(); 

        let isHorizontalSlider = current.parentElement && current.parentElement.classList.contains('slider');
        if (isHorizontalSlider && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
            let next = e.key === 'ArrowRight' ? current.nextElementSibling : current.previousElementSibling;
            if (next && next.classList.contains('tv-focusable')) {
                next.focus();
                let container = current.parentElement;
                let scrollPos = next.offsetLeft - (container.offsetWidth / 2) + (next.offsetWidth / 2);
                container.scrollTo({ left: scrollPos, behavior: 'smooth' });
            }
            return; 
        }

        let rect = current.getBoundingClientRect();
        let closest = null;
        let minDistance = Infinity;

        focusables.forEach(el => {
            if (el === current) return;
            let elRect = el.getBoundingClientRect();
            
            if (elRect.left <= rect.left && elRect.right >= rect.right && 
                elRect.top <= rect.top && elRect.bottom >= rect.bottom) {
                return;
            }
            
            let cX = rect.left + rect.width / 2;
            let cY = rect.top + rect.height / 2;
            let elCX = elRect.left + elRect.width / 2;
            let elCY = elRect.top + elRect.height / 2;

            let isDirectionMatch = false;

            if (e.key === 'ArrowRight' && elCX > cX) isDirectionMatch = true;
            if (e.key === 'ArrowLeft' && elCX < cX) isDirectionMatch = true;
            if (e.key === 'ArrowDown' && elCY > cY) isDirectionMatch = true;
            if (e.key === 'ArrowUp' && elCY < cY) isDirectionMatch = true;

            if (isDirectionMatch) {
                let xDist = Math.max(0, Math.max(rect.left - elRect.right, elRect.left - rect.right));
                let yDist = Math.max(0, Math.max(rect.top - elRect.bottom, elRect.top - rect.bottom));

                let yOverlap = (rect.bottom > elRect.top) && (rect.top < elRect.bottom);
                let xOverlap = (rect.right > elRect.left) && (rect.left < elRect.right);

                let distance;

                if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                    let overlapPenalty = yOverlap ? 0 : 10000;
                    distance = xDist + (yDist * 10) + overlapPenalty + Math.abs(elCY - cY);
                } else {
                    let overlapPenalty = xOverlap ? 0 : 10000;
                    // Changed spatial mapping here to calculate strictly by left alignment, neutralizing center-bias
                    distance = yDist + (xDist * 10) + overlapPenalty + Math.abs(elRect.left - rect.left);
                }

                if (distance < minDistance) {
                    minDistance = distance;
                    closest = el;
                }
            }
        });

        if (closest) {
            closest.focus();
            if (!isPlayerScope && !closest.classList.contains('dropdown-content') && !closest.classList.contains('player-settings-menu') && !closest.closest('#universalPlayerMenu')) {
                if (closest.closest('.hero-carousel')) {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                } else if (closest.closest('.slider-wrapper')) {
                    let wrapper = closest.closest('.slider-wrapper');
                    wrapper.scrollIntoView({behavior: "smooth", block: "center"});
                } else if (closest.closest('.modal-content')) {
                    closest.scrollIntoView({behavior: "smooth", block: "center"});
                } else if (closest.closest('.search-left')) {
                    // Don't scroll page when navigating within the keyboard panel
                } else if (closest.closest('.search-page')) {
                    closest.scrollIntoView({behavior: "smooth", block: "center"});
                } else if (closest.closest('nav')) {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                } else {
                    closest.scrollIntoView({behavior: "smooth", block: "center"});
                }
            }
        }
    });

    /* ==================== PWA INSTALLATION LOGIC ==================== */
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('sw.js').catch(err => console.log('Service Worker registration failed', err));
        });
    }

    let deferredPrompt;
    const installBtn = document.getElementById('installAppBtn');

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        if (installBtn) {
            installBtn.style.display = 'flex';
        }
    });

    if (installBtn) {
        installBtn.addEventListener('click', async () => {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;
                if (outcome === 'accepted') {
                    console.log('User accepted the install prompt');
                }
                deferredPrompt = null;
                installBtn.style.display = 'none';
            }
        });
    }

    window.addEventListener('appinstalled', (evt) => {
        console.log('YTFlix was installed');
        if (installBtn) installBtn.style.display = 'none';
    });

</script>
</body>
</html>