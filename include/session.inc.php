<?php

if (!defined('IN_FILE')) {
    exit(); // TODO report error
}

function userLoggedIn () {
    if (isset($_SESSION['id'])) {
        return $_SESSION['id'];
    } else {
        return false;
    }
}

function isStaff () {
    if (userLoggedIn() && $_SESSION['class'] >= CONFIG_UC_MODERATOR) {
        return true;
    } else {
        return false;
    }
}

function loginSessionRefresh() {

    if (!userLoggedIn()) {
        logout();
    }

    if ($_SESSION['fingerprint'] != getFingerPrint()) {
        logout();
    }

    session_regenerate_id(true);
}

function loginSessionCreate($postData) {

    global $db;

    $email = $postData[md5(CONFIG_SITE_NAME.'USR')];
    $password = $postData[md5(CONFIG_SITE_NAME.'PWD')];

    if(empty($email) || empty($password)) {
        stderr('Sorry', 'Please enter your email and password.');
    }

    $stmt = $db->prepare('SELECT id, passhash, salt, class, enabled FROM users WHERE email = :email');
    $stmt->execute(array(':email' => $email));
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!checkPass($user['passhash'], $user['salt'], $password)) {
        errorMessage('Login failed');
    }

    if (!$user['enabled']) {
        genericMessage('Ooops!', 'Your account is not enabled.
        If you have just registered, this is normal - an email with instructions will be sent out closer to the event start date!
        In all other cases, please contact the system administrator with any questions.');
    }

    logUserIP($user['id']);
    sessionVariableCreate($user);

    return true;
}

function logUserIP($userId) {
    global $db;

    if (!$userId) {
        errorMessage('No user ID was supplied to the IP logging function');
    }

    $stmt = $db->prepare('SELECT id, times_used FROM ip_log WHERE user_id=:user_id AND ip=INET_ATON(:ip)');
    $stmt->execute(array(':user_id' => $userId, ':ip'=>getIP()));
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);

    // if the user has logged in with this IP previously
    if ($entry['id']) {
        $stmt = $db->prepare('
        UPDATE ip_log SET
        last_used=UNIX_TIMESTAMP(),
        ip=INET_ATON(:ip),
        times_used=times_used+1
        WHERE id=:id
        ');
        $stmt->execute(array(
            ':ip'=>getIP(),
            ':id'=>$entry['id']
        ));
    }
    // if this is a new IP
    else {
        $stmt = $db->prepare('
        INSERT INTO ip_log (
        added,
        last_used,
        user_id,
        ip
        ) VALUES (
        UNIX_TIMESTAMP(),
        UNIX_TIMESTAMP(),
        :user_id,
        INET_ATON(:ip)
        )
        ');

        $stmt->execute(array(
            ':user_id'=>$userId,
            ':ip'=>getIP()
        ));
    }
}

function checkPass($hash, $salt, $password) {
    if ($hash == makePassHash($password, $salt)) {
        return true;
    }
    else {
        return false;
    }
}

function makePassHash($password, $salt) {
    return hash('sha256', $salt . $password . $salt . CONFIG_HASH_SALT);
}

function makeSalt() {
    return hash('sha256', generateRandomString());
}

function sessionVariableCreate ($user) {
    $_SESSION['id'] = $user['id'];
    $_SESSION['class'] = $user['class'];
    $_SESSION['enabled'] = $user['enabled'];
    $_SESSION['fingerprint'] = getFingerPrint();
}

function getFingerPrint() {
    return md5(getIP());
}

function sessionVariableDestroy () {
    session_unset();
    session_destroy();
}

function enforceAuthentication($minClass = CONFIG_UC_USER) {
    loginSessionRefresh();

    if ($_SESSION['class'] < $minClass) {
       logException(new Exception('Class less than required'));
       logout();
    }
}

function logout() {
    sessionVariableDestroy();
    header('location: '.CONFIG_INDEX_REDIRECT_TO);
    exit();
}

function registerAccount($postData) {
    global $db;

    if (!CONFIG_ACCOUNTS_SIGNUP_ALLOWED) {
        errorMessage('Registration is currently closed.');
    }

    $email = $postData[md5(CONFIG_SITE_NAME.'USR')];
    $password = $postData[md5(CONFIG_SITE_NAME.'PWD')];
    $team_name = $postData[md5(CONFIG_SITE_NAME.'TEAM')];

    if (empty($email) || empty($password) || empty($team_name) || empty($postData['type'])) {
        errorMessage('Please fill in all the details correctly.');
    }

    if (strlen($team_name) > CONFIG_MAX_TEAM_NAME_LENGTH || strlen($team_name) < CONFIG_MIN_TEAM_NAME_LENGTH) {
        errorMessage('Your team name was too long or too short.');
    }

    validateEmail($email);

    $stmt = $db->prepare('SELECT id FROM users WHERE team_name=:team_name OR email=:email');
    $stmt->execute(array(':team_name' => $team_name, ':email' => $email));
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user['id']) {
        errorMessage('An account with this team name or email already exists.');
    }

    $stmt = $db->prepare('
    INSERT INTO users (
    email,
    passhash,
    salt,
    team_name,
    added,
    enabled,
    type
    ) VALUES (
    :email,
    :passhash,
    :salt,
    :team_name,
    UNIX_TIMESTAMP(),
    '.(CONFIG_ACCOUNTS_DEFAULT_ENABLED ? '1' : '0').',
    :type
    )
    ');

    $salt = makeSalt();
    $stmt->execute(array(
        ':email' => $email,
        ':salt' => $salt,
        ':passhash' => makePassHash($password, $salt),
        ':team_name' => $team_name,
        ':type'=>$postData['type']
    ));

    // insertion was successful
    if ($stmt->rowCount()) {

        // log signup IP
        logUserIP($db->lastInsertId());

        // signup email
        $email_subject = 'Signup successful - account details';
        // body
        $email_body = $team_name.', your registration at '.CONFIG_SITE_NAME.' was successful.'.
        "\r\n".
        "\r\n".
        'Your username is: '.$email.
        "\r\n".
        'Your password is: ';

        $email_body .= (CONFIG_ACCOUNTS_EMAIL_PASSWORD_ON_SIGNUP ? $password : '(encrypted)');

        $email_body .=
        "\r\n".
        "\r\n".
        'Please stay tuned for updates!'.
        "\r\n".
        "\r\n".
        'Regards,'.
        "\r\n".
        CONFIG_SITE_NAME.
        "\r\n".
        CONFIG_SITE_URL;

        // send details to user
        sendEmail($email, $team_name, $email_subject, $email_body);

        // if account isn't enabled by default, display message and die
        if (!CONFIG_ACCOUNTS_DEFAULT_ENABLED) {
            genericMessage('Signup successful', 'Thank you for registering!
            Your chosen email is: ' . htmlspecialchars($email) . '.
            Please stay tuned for updates!');
        }
        else {
            return true;
        }
    }
    // no rows were inserted
    else {
        return false;
    }
}