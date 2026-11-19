<?php
declare(strict_types=1);

namespace Grav\Plugin\CwSocial;

use Grav\Common\Grav;

/**
 * Was wurde versucht, was ist geteilt -- je Seite und Kanal, in einer Datei
 * unter user/data/cw-social/protokoll.json.
 *
 * Warum das noetig ist: Bis 1.5.1 stand der Zustand allein im Frontmatter der
 * Seite. Der Beitrag ging zuerst raus, die Beitrags-Nummer wurde danach in die
 * Datei geschrieben. Ist `user/pages` schreibgeschuetzt -- bei einer per Git
 * ausgerollten Website der Normalfall --, scheitert dieses Schreiben, die
 * Seite gilt weiter als «noch nicht geteilt», und der naechste Lauf postet sie
 * erneut. Alle fuenf Minuten, ohne Ende (getgrav/grav#4401, Punkt 1).
 *
 * Darum gilt ab 1.6.0: Der Versuch wird festgehalten, BEVOR gepostet wird, und
 * zwar an einem Ort, der nicht Teil des Ausrollens ist. Das Frontmatter bleibt
 * die sichtbare Wahrheit fuer den Redakteur; dieses Protokoll ist die, auf die
 * sich der Worker verlaesst.
 *
 * Es ist ausserdem die Bremse: Nach `max_attempts` Fehlversuchen wird ein
 * Kanal einer Seite nicht mehr versucht. Ein Bild, das Instagram niemals
 * annimmt, laesst sonst jeden Lauf neun Sekunden in einer Seitenanfrage
 * warten.
 */
class Protokoll
{
    /** Eintraege aelter als das werden beim Speichern weggeraeumt (Tage). */
    private const AUFBEWAHREN = 180;

    private string $datei;
    /** @var array<string, array<string, mixed>> */
    private array $eintraege = [];
    private bool $gelesen = false;

    public function __construct(string $datei)
    {
        $this->datei = $datei;
    }

    /**
     * Der Ort des Protokolls, oder null, wenn dort nicht geschrieben werden
     * kann. Dann wird NICHT gepostet: Ohne Gedaechtnis gibt es keinen Schutz
     * gegen Doppel-Posts, und lieber kein Beitrag als jeden fuenften Minute
     * derselbe.
     */
    public static function fuer(Grav $grav): ?self
    {
        $ordner = (string) $grav['locator']->findResource('user-data://', true, true) . '/cw-social';
        if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
            return null;
        }
        $datei = $ordner . '/protokoll.json';
        if (is_file($datei) ? !is_writable($datei) : !is_writable($ordner)) {
            return null;
        }
        return new self($datei);
    }

    /** Schluessel eines Kanals einer Seite. */
    public static function schluessel(string $seitenPfad, string $kanal): string
    {
        return $kanal . ':' . $seitenPfad;
    }

    /** @return array<string, mixed> */
    public function eintrag(string $schluessel): array
    {
        $this->lesen();
        $e = $this->eintraege[$schluessel] ?? [];
        return is_array($e) ? $e : [];
    }

    /** Ist dieser Kanal dieser Seite laut Protokoll schon geteilt? */
    public function geteilt(string $schluessel): bool
    {
        return (string) ($this->eintrag($schluessel)['post_id'] ?? '') !== '';
    }

    /** Die Beitrags-Nummer aus dem Protokoll, oder ''. */
    public function postId(string $schluessel): string
    {
        return (string) ($this->eintrag($schluessel)['post_id'] ?? '');
    }

    /** Sind die Versuche aufgebraucht? */
    public function aufgegeben(string $schluessel, int $max): bool
    {
        $e = $this->eintrag($schluessel);
        return empty($e['post_id']) && (int) ($e['versuche'] ?? 0) >= max(1, $max);
    }

    /** Der letzte Fehler, fuer die Meldung im Protokoll des Servers. */
    public function fehlerText(string $schluessel): string
    {
        return (string) ($this->eintrag($schluessel)['fehler'] ?? '');
    }

    /**
     * Einen Versuch festhalten -- VOR dem Posten. Gibt die Nummer dieses
     * Versuchs zurueck. Schlaegt das Schreiben fehl, wird false gemeldet und
     * der Aufrufer postet nicht.
     */
    public function versuch(string $schluessel): ?int
    {
        $this->lesen();
        $e = $this->eintrag($schluessel);
        $n = (int) ($e['versuche'] ?? 0) + 1;
        $e['versuche'] = $n;
        $e['zuletzt']  = time();
        $this->eintraege[$schluessel] = $e;

        return $this->speichern() ? $n : null;
    }

    public function erfolg(string $schluessel, string $postId): void
    {
        $this->lesen();
        $this->eintraege[$schluessel] = [
            'post_id'  => $postId,
            'am'       => date('c'),
            'versuche' => (int) ($this->eintrag($schluessel)['versuche'] ?? 1),
            'zuletzt'  => time(),
        ];
        $this->speichern();
    }

    public function fehler(string $schluessel, string $meldung): void
    {
        $this->lesen();
        $e = $this->eintrag($schluessel);
        $e['fehler']  = mb_substr($meldung, 0, 500);
        $e['zuletzt'] = time();
        $this->eintraege[$schluessel] = $e;
        $this->speichern();
    }

    /** Fuer «Nochmals teilen»: der Kanal faengt von vorne an. */
    public function zuruecksetzen(string $schluessel): void
    {
        $this->lesen();
        unset($this->eintraege[$schluessel]);
        $this->speichern();
    }

    private function lesen(): void
    {
        if ($this->gelesen) {
            return;
        }
        $this->gelesen = true;
        if (!is_file($this->datei)) {
            return;
        }
        $roh = json_decode((string) @file_get_contents($this->datei), true);
        $this->eintraege = is_array($roh) ? $roh : [];
    }

    /**
     * Atomar schreiben: erst in eine Nachbardatei, dann umbenennen. Ein
     * abgebrochener Schreibvorgang laesst damit kein halbes JSON zurueck, das
     * beim naechsten Lauf als leeres Protokoll gelesen wuerde -- und ein
     * leeres Protokoll heisst «nichts geteilt».
     */
    private function speichern(): bool
    {
        $grenze = time() - self::AUFBEWAHREN * 86400;
        foreach ($this->eintraege as $k => $e) {
            if ((int) ($e['zuletzt'] ?? 0) < $grenze) {
                unset($this->eintraege[$k]);
            }
        }

        $temp = $this->datei . '.' . getmypid() . '.tmp';
        $json = json_encode($this->eintraege, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || @file_put_contents($temp, $json) === false) {
            @unlink($temp);
            return false;
        }
        if (!@rename($temp, $this->datei)) {
            @unlink($temp);
            return false;
        }
        return true;
    }
}
