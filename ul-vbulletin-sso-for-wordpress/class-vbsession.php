<?php
class VB_Session {
    private $vbdb;
    private $userinfo = array();
    private static $vbsession;

    /**
     * Gibt eine Instanz der WPDB-Zurück, über die sich Anfragen zur vB-DB ausführen lassen
     * @return mixed
     */
    public function getdb() {
        return $this->vbdb;
    }

    public static function current() {
        if( null == self::$vbsession ) {
            // Config mit den Zugangsdaten der vB-Datenbank einbinden
            require_once plugin_dir_path( __FILE__ ) . 'config.php';
            // vB-Session mit den Zugangsdaten der vB-Datenbank initialisieren (in config.php hinterlegt)
            self::$vbsession = new VB_Session(VBB_DB_USER, VBB_DB_PASSWORD, VBB_DB_NAME, VBB_DB_HOST, VBB_COOKIESALT, VBB_COOKIEPREFIX);
        }
        return self::$vbsession;
    }

    public function __construct($vbdb_user, $vbdb_password, $vbdb_name, $vbdb_host, $vb_cookiesalt, $vb_cookieprefix) {
        $this->vbdb = new wpdb( $vbdb_user, $vbdb_password, $vbdb_name, $vbdb_host );
        // vB-Einstellungen laden, da diese unabhängig von einer existierenden vB-Session benötigt werden
        $this->load_vbsettings();

        // Usersession ermitteln
        $client_sessionhash = $this->get_client_sessionhash();
        // Cookies ermitteln: Benutzer-Id
        // Als Fallback-Lösung wird nicht abgebrochen wenn der Cookie nicht gesetzt ist, da so auch Sessions ohne Cookies möglich sind
        $userid_cookie_name = $vb_cookieprefix . 'userid';
        $userid = ( isset( $_COOKIE[$userid_cookie_name] ) ? $_COOKIE[$userid_cookie_name] : 0 );
        // Passworthash
        $password_cookie_name = $vb_cookieprefix . 'password';
        $client_passwordhash = ( isset( $_COOKIE[$password_cookie_name] ) ? $_COOKIE[$password_cookie_name] : '' );
        // Userinfo laden, falls eine Session besteht
        $this->load_userinfo($userid, null, $client_sessionhash);
        // Wenn die Session keiner UserId zugeordnet werden kann, existiert sie nicht ODER der Nutzer surft als Gast
        if(  $this->userinfo == null || $this->userinfo['userid'] == 0 ) {
            $this->log('vBSession Init: Session für #' . $userid . ' konnte nicht ermittelt werden!');
            $this->set_loginstate( false );
            return;
        }
        $this->log( 'Session erkannt: Username = ' . $this->get('username') . ', vB UserId = ' . $this->get( 'userid' ) );
        // Wenn der User sich per vB-Session angemeldet hat muss sein clientseitiger PW-Hash verifiziert werden. Er kann sich jedoch auch über Wordpress mit seinem vB-Logindaten anmelden,
        // dann muss diese Prüfung wegfallen da keine aktive vB-Session existiert und somit der Vergleich des PWHashes fehlschlagen würde
        if( $this->get( 'authentificatedByWpLogin', false ) && $this->get( 'userid' ) > 0 ) {
            $this->set_loginstate( true );
        }else {
            // Hat der User sich per vB-Session authentifiziert, muss der Passwort-Hash aus dem Cookie mit dem der Datenbank verglichen werden
            $db_passwordhash = md5( $this->userinfo['password'] . $vb_cookiesalt );
            $passwordhash_isvalid = ( $db_passwordhash == $client_passwordhash );
            $this->log( 'Authentifiziere vB-User mit PW-Hash via Cookie, Result = ' . $passwordhash_isvalid );
            $this->set_loginstate( $passwordhash_isvalid );
        }
    }

    /**
     * Läd die Nutzerinformationen eines vB-Users anhand verschiedener Kriterien
     * @param $userid          Id des Nutzers (Id oder Benutzername sind Pflichtfeld)
     * @param $username        Benutzername (Id oder Benutzername sind Pflichtfeld)
     * @param $sessionhash     Sessionhash des Nutzers
     */
    public function load_userinfo($userid, $username, $sessionhash = null) {
        // Identifiziert den User anhand seiner BenutzerId oder des Benutzernamens
        $user_ident_where_sql = '';
        if( $userid > 0 ) {
            $user_ident_where_sql = 'user.userid = ' . (int)$userid;
        } else if( strlen( $username ) > 0 ) {
            $user_ident_where_sql = 'user.username = "' . esc_sql($username) . '"';
        }else {
            // Benutzer hat keine vB-Session: Prüfen, ob er bereits in Wordpress angemeldet ist (Sonderfall wenn vB nicht eingeloggt und Login via WP mit den vB-Logindaten erfolgt)
            if( !function_exists( 'wp_get_current_user' ) ) {
                // Abhängigkeiten von get_current_user_id() werden erst später von WP geladen, daher muss die Datei pluggable.php an dieser Stelle eingebunden werden
                include(ABSPATH . 'wp-includes/pluggable.php' );
            }
            $current_wpuserid = get_current_user_id();
            if( $current_wpuserid > 0 ) {
                // Der aktuelle Nutzer hat KEINE vB-Session aber ist via WP angemeldet: Prüfen ob ein verknüpfter vB-Account besteht
                $current_related_vbuserid = (int)get_user_meta( $current_wpuserid, 'vbuserid', true );
                if( $current_related_vbuserid > 0 ) {
                    // User ist via WP angemeldet, ihm wird daher Zugriff auf die dazugehörige vB-Session gewährt
                    $user_ident_where_sql = 'user.userid = ' . $current_related_vbuserid;
                    // Dass der User via WP angemeldet wurde muss in der UserInfo vermerkt werden, da der spätere Vergleich des PwHash-Cookies dann wegfällt.
                    // Ansonsten würde der User irrtümlicherweise als Nicht-Angemeldet gekennzeichnet werden, da der Cookie aufgrund der nicht vorhandenen vB-Session fehlt oder invalid ist.
                    $this->append_to_userinfo( array( 'authentificatedByWpLogin' => true ) );
                }
            }
            // Wenn der SQL-String an dieser Stelle immer noch leer ist kann der User nicht authentifiziert werden und wird als Gast behandelt
            // Ihm wird in der Abfrage die dazugehörige vB-Usergruppe #1 zugewiesen, damit er die Gastrechte erhält (z.B. für den Zugriff auf bestimmte Themen/Posts)
            if( strlen( $user_ident_where_sql ) == 0) {
                $user_ident_where_sql = 'usergroup.usergroupid = 1';
            }
        }
        // Clientseitiger vB-Sessionhash validieren, sofern vorhanden
        $sessionhash_sql = '';
        if ( $sessionhash != null && strlen( $sessionhash ) > 0 )
            $sessionhash_sql = ' AND session.idhash = "' . esc_sql( $sessionhash ) . '"';
        // Userinfo laden
        $userinfo = $this->vbdb->get_row('
            SELECT usergroup.forumpermissions,
                user.username, user.userid, user.password, user.salt, user.email, user.usergroupid, user.lastvisit, user.avatarrevision, user.usergroupid,
                session.sessionhash
            FROM  ' . $this->vbdb->prefix . 'usergroup
            LEFT JOIN user ON (usergroup.usergroupid = user.usergroupid)
            LEFT JOIN session ON(
                session.userid = user.userid
                ' . $sessionhash_sql . '
            )
            WHERE ' . $user_ident_where_sql
            , ARRAY_A);
        // Userinfo zur aktuellen Instanz hinzufügen
        $this->append_to_userinfo( $userinfo );
        // Avatar-URL des aktuellen Nutzers erzeugen
        if( $this->get( 'avatarrevision' ) > 0 ) {
            $this->userinfo['relative_avatarpath'] = '/avatar' . $this->get( 'userid' ) . '_' . $this->get( 'avatarrevision' ) . '.gif';
            $this->userinfo['avatarurl'] = $this->get( 'vb_base_avatarurl' ) . $this->get( 'relative_avatarpath' );
        }else {
            $this->userinfo['avatarurl'] = $this->userinfo['relative_avatarpath'] = null;
        }
    }

    /**
     * Läd wichtige vB-Einstellungen wie die Basis-URL des Forums und die volle URL des Nutzeravatars
     */
    public function load_vbsettings() {
        // vB-Einstellungen laden: Forums-URL und Base-URL für Avatare
        $bb_settings = $this->vbdb->get_results( '
            SELECT varname, value from ' . $this->vbdb->prefix . ' setting
            WHERE varname IN( "bburl", "avatarurl" )
        ', OBJECT_K );
        $this->userinfo['vb_baseurl'] = $bb_settings['bburl']->value;
        $this->userinfo['vb_base_avatarurl'] = $this->userinfo['vb_baseurl'] . '/' . $bb_settings['avatarurl']->value;
    }

    private function set_loginstate($login_state) {
        if( !$login_state ) {
            $this->append_to_userinfo(array(
                'userid' => 0,
                // Benutzergruppe 'Nicht angemeldete User', damit beim auslesen der Beiträge im Widget die Berechtigungen für Threads korrekt ermittelt werden können
                'usergroupid' => 1,
                'isloggedin' => false
            ));
        }else {
            $this->userinfo['isloggedin'] = $login_state;
        }
    }

    /**
     * Fügt ein assoziatives Array zur aktuellen Userinfo hinzu, ohne dabei bestehende Werte der Userinfo (z.B. aus der vB-Config) zu überschreiben
     * @param $userinfoarray Hinzuzufügendes Array im Fornat KEY => VALUE
     */
    private function append_to_userinfo( $userinfoarray ) {
        if( null == $userinfoarray )
            return;
        $this->userinfo = array_merge( $this->userinfo, $userinfoarray );
    }

    /**
     * Generiert den clientseitigen vB-Sessionhash, der sich aus IP/User-Agent zusammensetzt
     * @return string   Sessionhash der aktuellen vB-Session
     */
    private function get_client_sessionhash() {
        $ipsegments = explode( '.', $_SERVER['REMOTE_ADDR'] );
        $ip = implode( '.', array_slice( $ipsegments, 0, 4 - 1 ) );
        $sessionhash = md5( $_SERVER['HTTP_USER_AGENT'] . $ip );
        return $sessionhash;
    }

    /**
     * Gibt einen Array von ForumIds zurück, auf die der aktuell angemeldete Nutzer nicht mindestens Leserechte hat
     * @return array
     */
    public function get_not_viewable_forums() {
        $bitfield_canview = 1; // ~ $vbulletin->bf_ugp_forumpermissions['canview']
        $query = $this->vbdb->prepare('
            SELECT forum.forumid, forum.parentid, forumpermission.forumpermissions
            FROM ' . $this->vbdb->prefix . 'forum
            LEFT JOIN forumpermission ON (forumpermission.forumid = forum.forumid AND forumpermission.usergroupid = %d)
        ', $this->get( 'usergroupid' ) );
        // Foren als assoziatives Array mit dem 1. Column (forumid) als Schlüssel speichern, damit später problemlos Elternforen ermittelt werden können
        $forums = $this->vbdb->get_results( $query, OBJECT_K );
        $not_viewable_forums = array( );
        // Die Rechte jedes Forums ermitteln
        foreach( $forums as $forumid => $forum ) {
            // NULL bedeutet keine spezifischen Rechte sind gesetzt - In diesem Fall werden die Rechte des übergeordneten Forums verwendet, sofern vorhanden
            if( $forum->forumpermissions === null && $forum->parentid != -1 ) {
                // Alle möglichen Verschachtelungen abdecken, in dem rekursiv nach Subforen mit Rechten gesucht wird
                $parentforum = $forums[$forum->parentid];
                while( $parentforum->parentid != -1 && $parentforum->forumpermissions === null ) {
                    $parentforum = $forums[$parentforum->parentid];
                }
                // Möglicherweise gibt es selbst in den übergeordneten Foren keine Rechte, daher prüfen, ob welche gefunden wurden oder nicht
                if( $parentforum->forumpermissions !== null ) {
                    $forum->forumpermissions = $parentforum->forumpermissions;
                }
            }
            // Wurden keine Rechte ermittelt, gelten die Rechte der Benutzergruppe des Nutzers
            if( $forum->forumpermissions === null ) {
                $forum->forumpermissions = $this->get( 'forumpermissions' );
            }
            // Prüfen ob der Nutzer Leserechte besitzt, falls nein die ForenId zur Liste der nicht einsehbaren Foren hinzufügen
            $canview = $forum->forumpermissions & $bitfield_canview;
            if( !$canview ) {
                $not_viewable_forums[] = $forum->forumid;
            }
        }
        return $not_viewable_forums;
    }

    /**
     * Gibt den Wert eines Sclüssel aus der UserInfo des aktuellen vB-Nutzers zurück oder null, wenn dieser nicht gefunden wurde
     * @param $key              Schlüssel dessen Wert ermittelt werden soll
     * @param $defaultValue     Wert der Zurückgegeben werden soll, wenn $key nicht existiert (standardmäßig NULL)
     * @return null
     */
    public function get( $key, $defaultValue = null ) {
        // Schlüssel im Userinfo-Array suchen
        if( $this->userinfo != null && isset( $this->userinfo[$key] ) ) {
            return $this->userinfo[$key];
        }
        return $defaultValue;
    }

    /**
     * Prüft, ob die Anmeldeinformationen des aktuellen Nutzers gültig sind
     * @param $username     vB Benutzername
     * @param $password     vB Password im Klartext
     * @return bool
     */
    public function check_credentials($username, $password) {
        $this->load_userinfo(null, $username);
        // Nutzername nicht gefunden
        if( $this->get( 'userid' ) == 0 )
            return false;
        // Passwordhash erzeugen und mit dem in der Datenbank gespeicherten abgleichen
        $client_pwhash = md5(md5($password) . $this->get( 'salt' ));
        return ( $client_pwhash == $this->get( 'password' ) );
    }

    /**
     * Ermittelt den lokalen Wordpress-Benutzer, welcher mit dem aktuellen vB-Nutzer aus dieser Instanz verknüpft ist
     * @param null $wp_fields   Felder des Nutzers die abgefragt werden sollen (Optional, wenn nicht gesetzt oder null wird die gesamte Userinfo geladen)
     * @return mixed            WP Userinfo bestehend aus $wp_fields oder ein String wenn nur ein Feld abgefragt wird - Null, falls der Nutzer nicht existiert
     */
    public function get_related_wpuser($wp_fields = null) {
        // Userabfrage anhand des Meta-Keys der vB-UserId des aktuell angemeldeten Users ausrichten
        return self::get_wpuser_by_vbuserid( $this->get( 'userid' ), $wp_fields );
    }

    public static function get_wpuser_by_vbuserid($vb_userid, $wp_fields = null) {
        $get_user_params = array(
            'meta_key' => 'vbuserid',
            'meta_value' => $vb_userid
        );
        // Aus Performance-Gründen lediglich die benötigten Felder abfragen, sofern nicht die komplette WP-Userinfo benötigt wird
        if( $wp_fields != null ) {
            $get_user_params['fields'] = $wp_fields;
        }
        // ToDo: Auf PHP5.4 updaten, dann kann direkt über get_users($get_users_params)[0] darauf zugegriffen werden
        $wpusers = get_users( $get_user_params );
        if( count( $wpusers ) == 0)
            return null;
        return $wpusers[0];
    }

    /**
     * Loggt den zu dieser Instanz gehörenden vB-Nutzer in Wordpress ein
     * @param null $wpuserid    Id des Wordpress-Benutzers oder null, wenn der Wp-Benutzer anhand des vB-Nutzers ermittelt werden soll
     * @return bool
     */
    public function login_wpuser($wpuserid = null) {
        // WP-UserId ermitteln falls nicht übergeben
        if( $wpuserid == null)
            $wpuserid = $this->get_related_wpuser( 'id' );
        // Cookies löschen und den User als eingeloggten Nutzer setzen
        //wp_clear_auth_cookie();
        $loggedin_user = wp_set_current_user( $wpuserid );
        return ( $loggedin_user != null );
    }

    /**
     * Erzeugt einen Wordpress-Benutzer auf Basis des zu dieser Instanz gehörenden vB-Nutzers
     * @return bool
     */
    public function create_wpuser() {
        $created_userid = wp_create_user( $this->get( 'username' ), '', $this->get( 'email' ) );
        if( is_wp_error ( $created_userid ) ) {
            // ToDo: Fehler loggen
            echo "Fehler beim anlegen des Users: " . $created_userid->get_error_message();
            return false;
        }
        // User wurde erfolgreich angelegt: VB Userid in seinen Meta-Daten hinzufügen
        update_user_meta( $created_userid, 'vbuserid', $this->get( 'userid' ) );
        update_user_meta( $created_userid, 'vb_relative_avatarpath', $this->get( 'relative_avatarpath' ) );
        return $created_userid;
    }

    /**
     * Synchronisiert den vB-Account mit WP
     * @param $wpuser WP-User Instanz (Benötigt Attribute: display_name, user_email)
     */
    public function sync_vbuser_with_wp( $wpuser_id, $wpuser_display_name, $wpuser_email ) {
        // Meta-Attribute prüfen: Avatar
        $wp_avatarpath = get_user_meta( $wpuser_id, 'vb_relative_avatarpath', true );
        if( $wp_avatarpath !== $this->get( 'relative_avatarpath' ) ) {
            update_user_meta( $wpuser_id, 'vb_relative_avatarpath', $this->get( 'relative_avatarpath' ) );
        }

        // Stammdaten des Nutzers prüfen
        $new_userdata = array();
        // Benutzer wurde in vB umbenannt
        if( $this->userinfo['username'] != $wpuser_display_name)
            $new_userdata['display_name'] = $this->userinfo['username'];
        // Benutzer hat in vB seine eMail-Adresse geändert
        if( $this->userinfo['email'] != $wpuser_email )
            $new_userdata['user_email'] = $this->userinfo['email'];
        // Wurden Daten geändert, muss die WP-Datenbank aktualisiert werden
        if( count( $new_userdata ) > 0 ) {
            // ID des verwandten WP-Users hinzufügen, damit WP weiß welcher Benutzer aktualisiert werden soll
            $new_userdata['ID'] = $wpuser_id;
            // WP-User aktualisieren
            $updated_userid = wp_update_user( $new_userdata );
            return ( $updated_userid > 0 );
        }
    }

    public function log($message) {
        //echo '<script>console.log("[vB-Session] ' . $message . '");</script>';
    }
}
?>