<?php
//ini_set('display_errors', 1);
/*
	Plugin Name: vBulletin-Authentifizierung in Wordpress
	Plugin URI: https://u-labs.de
	Description: Ermöglicht es, Nutzer anhand von vB zu authentifizieren
	Version: 0.1
	Author: DMW007
	Author URI: https://u-labs.de
	License: GPL
*/
class VB_Authentification {
	private $vbsession;

	public function __construct() {
		define( 'VBA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
		// vB-Session initialisieren
		require_once VBA_PLUGIN_DIR . 'class-vbsession.php';
		$this->vbsession = VB_Session::current();
		//if( !$this->vbsession->get( 'isloggedin' ) )
		//	return false;
		##### Actions #####
		// Bei jedem Seitenaufruf prüfen, ob der User über seine vB-Session eingeloggt werden kann
		add_action( 'init' , array( $this, 'check_vbsession' ) );
		// Logins über vB abwickeln
		add_action( 'authenticate' , array( $this, 'vb_login' ) );
		// Auch beim Aufruf des Login-Formulars auf existierende vB-Sessions checken
		add_action( 'login_form_login', array( $this, 'login_form_display' ) );
		add_action( 'wp_logout', array( $this, 'logout' ) );
		// vB-Avatar in WP integrieren
		add_filter( 'get_avatar', array( $this, 'get_avatar' ), 1, 5 );
		// WP-User sollen ihr Profil in vB bearbeiten und nicht in WP
		add_action( 'admin_init', array( $this, 'profile_edit_redirect_vbusers' ) );
		// Zusätzliches Profilfeld mit der verknüpften vB-Userid einfügen und für Admins änderbar gestalten
		add_action( 'edit_user_profile', array( $this, 'add_vbuserid_profilefield' ) );
		add_action( 'edit_user_profile_update', array( $this, 'update_vbuserid_profilefield' ) );
	}

	/**
	 * Bettet das vB-Avatar in WP ein, sofern verfügbar
	 * @param $avatar
	 * @param $id_or_email
	 * @param $size
	 * @param $default
	 * @param $alt
	 * @return string
	 */
	public function get_avatar( $avatar, $id_or_email, $size, $default, $alt ) {
		// $id_or_email kann eine UserId, eMail ODER ein Kommentar-Objekt sein (https://codex.wordpress.org/Plugin_API/Filter_Reference/get_avatar)
		if( is_object( $id_or_email ) ) {
			// In Kommentaren ist $id_or_email ein Kommentar-Objekt, die Id des kommentierenden Users befindet sich im Attribut user_id
			$id_or_email = (int)$id_or_email->user_id;
		}else if( !$id_or_email ) {
			// Ist der Wert nicht definiert, handelt es sich um den aktuell angemeldeten User
			global $current_user;
			$id_or_email = $current_user->ID;
		}

		// Die Id des WP-Users liegt nun vor: Prüfen ob ein vB-Avatar in den Meta-Daten des Nutzers gespeichert ist
		$vb_relative_avatarpath = get_user_meta( $id_or_email, 'vb_relative_avatarpath', true );
		if( null !== $vb_relative_avatarpath && strlen( $vb_relative_avatarpath ) > 0 ) {
			// Der User hat ein vB-Avatar hinterlegt
			return '<img src="' . $this->vbsession->get( 'vb_base_avatarurl' ) .  $vb_relative_avatarpath . '">';
		}else {
			// Platzhalter (ToDo: In Einstellungen verlagern)
			return '<img src="https://u-img.net/img/4037Ld.png">';
		}
		// Es handelt sich um keinen vB-User oder dieser hat kein Avatar im Forum hochgeladen, am Standard-Avatar werden daher keine Änderungen vorgenommen
		return $avatar;
	}

	/**
	 * Wird aufgerufen, wenn das Login-Formular von wp-login.php aufgerufen wird (z.B. weil der User sich neu authentifizieren muss)
	 */
	public function login_form_display() {
		// Verfügt der User über eine valide vB-Session, wird die Neu-Authentifizierung deaktiviert
		if( null !== $this->vbsession->get_related_wpuser() ) {
			unset($_REQUEST['reauth']);
		}
	}

	/**
	 * Ermittelt eine vorhandene vB-Session und loggt den Nutzer in Wordpress für den aktuellen Seitenaufruf damit ein
	 * @return bool|void
	 */
	public function check_vbsession() {
		// UPDATE: Wurde entfernt, damit Zuordnungen auch dann möglich sind, wenn sich der User in WP mit seinen vB Zugangsdaten anmeldet
		// Ist ein Nutzer bereits nativ in Wordpress angemeldet werden vB-Sessions nicht berücksichtigt
		//if ( is_user_logged_in() ) { echo 'already loggedin';
		//	$this->vbsession->log('Session-Check: Nutzer ist bereits via WP eingeloggt.');
		//	return;
		//}
		// User ist nicht in WP angemeldet: Prüfen ob eine vB-Session aktiv ist
		if( !$this->vbsession->get( 'isloggedin' ) )
			return false;
		// Eine aktive vB-Session existiert: Prüfen, ob ein verwandter WP-Benutzer existiert
		$wpuser = $this->vbsession->get_related_wpuser( array( 'id', 'display_name', 'user_email' ) );
		if( false == $wpuser || $wpuser->id == 0 ) {
			$this->vbsession->log("vB-User nicht in WP gefunden, erzeuge Account...\n");
			// WP-Account existiert nicht, daher wird auf Basis des vB-Accounts ein verknüpfter angelegt
			$new_wpuserid = $this->vbsession->create_wpuser();
			$this->vbsession->log("Neuen WP-Nutzer erstellt! Id = $new_wpuserid Login...");
		}else {
			$this->vbsession->log("WP-Nutzer existiert bereits! Verwandter WP-User: #" . $wpuser->id . " -> Starte Login...");
		}
		// Letzter Schritt -> Prüfen ob der User gesperrt ist (Benutzergruppen: Gesperrt, Permanent Gesperrt)
		// ToDo: In WP-Einstellung umwandeln, welche Gruppen-Ids ausgeschlossen werden sollen
		$banned_usergroup_ids = array( 8, 17 );
		if( !in_array( $this->vbsession->get( 'usergroupid' ), $banned_usergroup_ids ) ) {
			// User ist nicht gesperrt: WP-Account mit vB synchronisieren und Nutzer einloggen
			$this->vbsession->sync_vbuser_with_wp( $wpuser->id, $wpuser->display_name, $wpuser->user_email );
			$this->vbsession->login_wpuser();
		}else {
			// Benutzer ist gesperrt - Sämtliche möglicherweise aktive Sessions werden beendet, sodass er keine Aktionen innerhalb WP unternehmen kann
			$user_sessions = WP_Session_Tokens::get_instance( $wpuser->id );
			$user_sessions->destroy_all();
		}
	}

	/**
	 * @param $usr
	 */
	public function vb_login( $usr) {
		// Ist der Nutzer bereits eingeloggt? Falls ja wird der verknüpfte WP-User zurückgegeben, sodass der Login entfällt
		$wpuser = $this->vbsession->get_related_wpuser();
		if ( null !== $wpuser ) {
			return $wpuser;
		}
		// Es existiert keine vB-Session, stattdessen hat der Nutzer Logindaten (User + Passwort) angegeben die über vB verifiziert werden müssen
		$username_isset = ( isset( $_POST['log'] ) && strlen( $_POST['log'] ) > 0 );
		$password_isset = ( isset( $_POST['pwd'] ) && strlen( $_POST['pwd'] ) > 0 );

		if( $username_isset && $password_isset && $this->vbsession->check_credentials( $_POST['log'], $_POST['pwd'] ) ) {
			$wpuser = $this->vbsession->get_related_wpuser();
			if( $wpuser == null ) {
				// Verknüpfter WP-User existiert noch nicht und muss erst angelegt werden
				$this->vbsession->create_wpuser();
				$wpuser = $this->vbsession->get_related_wpuser();
			}
			return $wpuser;
		}
	}

	/**
	 * Loggt den aktuell angemeldeten User aus
	 */
	public function logout() {
		$current_user = wp_get_current_user();
		$user_sessions = WP_Session_Tokens::get_instance( $current_user->id );
		$user_sessions->destroy_all();
	}
	/**
	 * Leitet den Nutzer von der WP-Profil-Editseite auf das vB-Benutzerkontrollzentrum um, wenn er aus vB importiert wurde
	 */
	public function profile_edit_redirect_vbusers() {
		$action = ( isset( $_GET['action'] ) ? $_GET['action'] : null);
		if(IS_PROFILE_PAGE === true && $action != 'logout' ) {
			// Wenn der aktuelle WP-User nicht mit vB verknüpft ist, darf er sein Profil via WP ändern
			$related_vbuserid = get_user_meta( $this->vbsession->get_related_wpuser( 'id' ), 'vbuserid', true);
			// 2. Sonderfall: Administratoren dürfen ebenfalls ihr WP-Profil bearbeiten
			// ToDo: Wordpress-Einstellung für das AdminCP erzeugen
			$is_admin = ($this->vbsession->get('usergroupid') == 9);
			if( null == $related_vbuserid || $is_admin )
				return;
			// Der aktuelle Benutzer wurde aus vB importiert und wird daher in das Benutzerkontrollzentrum umgeleitet
			//wp_die( 'Bitte nutze das Community-Kontrollzentrum, um dein Profil zu bearbeiten!' );
			wp_redirect( $this->vbsession->get( 'vb_baseurl' ) . '/profile.php?do=editpassword' );
			exit();
		}
	}

	/**
	 * Läd die View, welche das Profilfeld für die Benutzer-Id des verknüpften vBulletin-Kontos einfügt
	 */
	public function add_vbuserid_profilefield() {
		// Id des verknüpften vB-Benutzers oder 0, falls keiner existiert
		$related_vbuserid = get_user_meta( (int)$_GET['user_id'], 'vbuserid', true );
		if( 0 == $related_vbuserid ) {
			// Platzhalter, falls der Nutzer kein verknüpftes vBulletin-Konto besitzt
			$related_vbuserid = '-';
		}
		include VBA_PLUGIN_DIR . 'view/vbuserid_profilefield.php';
	}

	/**
	 * Speichert das Profilfeld für die verknüpfte vBulletin UserId
	 */
	public function update_vbuserid_profilefield() {
		$wp_userid = (int)$_POST['user_id'];
		$new_related_vbuserid = (int)$_POST['related_vbuserid'];
		// Profilfeld nur aktualisieren, wenn eine valide UserId und kein Platzhalter wie 0 oder - empfangen wurde
		if( $new_related_vbuserid > 0 ) {
			update_user_meta( $wp_userid, 'vbuserid', $new_related_vbuserid );
		}
	}

}

new VB_Authentification();
