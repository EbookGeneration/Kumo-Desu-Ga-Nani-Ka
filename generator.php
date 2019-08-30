<?php
/**
 * ===========================
 * = Générateur de chapitres =
 * ===========================
 * Générateur pour l'intégration des chapitres.
 *
 * Ce script fourni les fonctionnalités suivantes :
 *  - Création fichier HTML du chapitre :
 *    # Mise en forme des analyses des monstres (appraisal-sama) sous la forme d'un tableau
 *  - Référencement du fichier HTML dans la structure du livre (content.opf)
 *  - Ajout du chapitre à la table des matières (toc.ncx)
 *
 */

use Fidit\v4\CommandLineArguments\Argument\Option\Value as OptionValue;
use Fidit\v4\CommandLineArguments\Argument\Parser\StringParser;
use Fidit\v4\CommandLineArguments\Argument\Value\Value;
use Fidit\v4\CommandLineArguments\CommandLineArguments;
use Fidit\v4\CommandLineArguments\Solution;

require_once 'fidit/libs.php';

/*
 * Gestion des arguments
 */
$description = <<<TXT
Ce script fourni les fonctionnalités suivantes :
  - Création fichier HTML du chapitre :
    # Mise en forme des analyses des monstres (appraisal-sama) sous la forme d'un tableau
  - Référencement du fichier HTML dans la structure du livre (content.opf)
  - Ajout du chapitre à la table des matières (toc.ncx)
TXT;

$cmdline = new CommandLineArguments('Generateur de chapitres', $description, 'php generator.php');
$cmdline->addDefaultArguments();

$cmdline->addSolution(0,
    (new Solution())
        ->addOption(
            (new OptionValue('chapter', 'Le numéro de chapitre', new StringParser()))
                ->setTagShort('c')
        )
        ->addArgument(0,
            (new Value('url', 'L\'URL du chapitre', new StringParser()))
        )
);

$args = $cmdline->parse();
$cmdline->treatDefaultArguments($args);

$chapter = new Chapter($args->url, $args->chapter);
$chapter->format();

$chapter->save();
$chapter->addToStructure();
$chapter->addToSummary();

/**
 * Un chapitre
 */
class Chapter {
    private const DIRNAME_TEXT   = 'text/';
    private const DIR_TEXT       = __DIR__.'/'.self::DIRNAME_TEXT;
    private const FILE_STRUCTURE = __DIR__.'/content.opf';
    private const FILE_SUMMARY   = __DIR__.'/toc.ncx';

    /**
     * @var ChapterNumber Le numéro du chapitre
     */
    private $_number;
    /**
     * @var string Le titre du chapitre
     */
    private $_title;
    /**
     * @var string Le contenu HTML de *chapitre* (et non de toute la page)
     */
    private $_body;

    /**
     * Nouveau chapitre
     *
     * @param string      $url     L'URL du chapitre
     * @param string|null $chapter Le nom du chapitre. NULL si à extraire
     */
    public function __construct ($url, $chapter = null) {
        echo_p('Lecture chapitre #'.(is_null($chapter) ? '?' : $chapter).' : '.$url);
        $this->_number = new ChapterNumber($chapter);

        echo_p('Chargement URL', 1);
        $html = file_get_contents($url);
        if($html === false)
            echo_p('ERREUR chargement contenu page', 2, true);

        echo_p('Extraction du titre', 1);
        if(!preg_match('@<h3 class=\'post-title entry-title\' itemprop=\'name\'>\s*Kumo Desu [gk]a,? Nani [kg]a\? (.+?)\s*</h3>@is', $html, $matches))
            echo_p('ERREUR extraction titre', 2, true);
        $this->_title = $matches[1];

        echo_p('Extraction numéro chapitre', 1);
        if(preg_match('@^Chapter ([0-9]+)@i', $this->_title, $matches)) {
            if(is_null($chapter))
                $this->_number->setRaw($matches[1]);

            if($this->_number->raw() != $matches[1])
                echo_p('Incohérence entre le numéro de chapitre donné ('.$this->_number->raw().') et celui extrait ('.$matches[1].')', 2, true);
        }
        else {
            if(is_null($chapter))
                echo_p('Numéro de chapitre manquant et impossible à extraire', 2, true);
        }

        echo_p('Extraction HTML du chapitre', 1);
        if(!preg_match('@<div class=\'post-body entry-content\' id=\'post-body-[0-9]+\' itemprop=\'description articleBody\'>\s*(.+?)</div>\s*<div class=\'post-footer\'>@is', $html, $matches))
            echo_p('Echec extraction', 2, true);
        $this->_body = $matches[1];

        echo_p('Supression répétition titre', 1);
        $prefix = preg_replace('@^Chapter @i', '', $this->_title);
        if(!preg_match('@<b><span style="font-size: large;">'.preg_quote($prefix).' ([^>]+)</span></b>@i', $this->_body, $matches, PREG_OFFSET_CAPTURE))
            if(!preg_match('@<span style="font-size: large;"><b>'.preg_quote($prefix).' ([^>]+)</b></span>@i', $this->_body, $matches, PREG_OFFSET_CAPTURE))
                $matches = null;
        if(!is_null($matches)) {
            $this->_title = self::_replaceWidthChars($matches[1][0]);
            $this->_body = substr($this->_body, $matches[0][1] + strlen($matches[0][0]));
        }

        echo_p('===== Chapitre n° '.$this->_number->raw().' : '.$this->_title.' =====', 1);

        echo_p('Supression titre du corps du chapitre', 1);
        $this->_body = preg_replace('@^.+<b><span style="font-size: large;">[^<]+</span></b><br />\s*<br />@is', '', $this->_body);
    }

    /**
     * Formate le contenu du chapitre
     */
    public function format () {
        echo_p('Met en forme le contenu du chapitre');
        $this->_body = self::_replaceWidthChars($this->_body);

        $this->_format_appraisal();

        $this->_body = preg_replace('@&#12300;(.+?)&#12301;@', '"$1"', $this->_body);       // Textes spéciaux
        $this->_body = preg_replace('@&#12302;(.+?)&#12303;@', '[$1]', $this->_body);       // Noms des compétances
        $this->_body = preg_replace('@&#12298;(.+?)&#12299;@', '{$1}', $this->_body);       // Voix du "système"

        $this->_format_paragraphAndDialogs();

        $this->_body = preg_replace('@-{5,}@i', '</p><hr /><p>', $this->_body);     // Lignes de séparation
        $this->_body = preg_replace('@<p>\s*</p>@i', '', $this->_body);             // Paragraphes vide

        $this->_body = preg_replace('@(?<=})\s*(?={)@i', '<br />', $this->_body);       // Retours à la ligne suplémentaire entre deux voix "système"
        $this->_body = preg_replace('@(?<=])\s*(?=\[)@i', '<br />', $this->_body);     // Retours à la ligne suplémentaire entre deux compétances

        $this->_body = preg_replace('@</?(?:a|b|span|div)(?: .+?)?>@i', '', $this->_body);      // Tags inutiles
        $this->_body = preg_replace('@&nbsp;@i', '', $this->_body);                             // Espaces insécable

        // Les tags "manuel"
        $this->_body = preg_replace('@<([a-z0-9_]+)_manual />@i', '<$1 />', $this->_body);
        $this->_body = preg_replace('@<(/?[a-z0-9_]+)_manual>@i', '<$1>', $this->_body);

        $this->_body = preg_replace('@^\s+@i', '', $this->_body);           // Espaces au début
        $this->_body = preg_replace('@\s+$@i', '', $this->_body);           // Espaces à la fin

        $this->_body = '<p>'.$this->_body.'</p>';
    }

    /**
     * Formate les "résultats" de la compétance "appraisal"
     */
    private function _format_appraisal () {
        $nb = 0;

        // Grands blocs
        $this->_body = preg_replace_callback(
            /** @lang PhpRegExp */'@<br />\s*<br />\s*&#12302;(?<species>.+?(?=&#12288;))(?:&#12288;LV(?<level>[0-9]+))?(?:&#12288;(?:(?<failed_name>Failed to appraise its status)|(?:Name&#12288;(?<name>.+?(?=<br />|&#12288;|&#12303;))))(?:&#12288;)?)?'.
            '(?:<br />\s*Status<br />\s*'.
                '&#12288;HP&#65306;(?<hp_current>[0-9]+)&#65295;(?<hp_max>[0-9]+)&#65288;Green&#65289;(?:&#65288;(?<hp_level>[0-9]+)up&#65289;)?<br />\s*'.
                '&#12288;MP&#65306;(?<mp_current>[0-9]+)&#65295;(?<mp_max>[0-9]+)&#65288;Blue&#65289;(?:&#65288;(?<mp_level>[0-9]+)up&#65289;)?<br />\s*'.
                '&#12288;SP&#65306;(?<sp_current>[0-9]+)&#65295;(?<sp_max>[0-9]+)&#65288;Yellow&#65289;(?:&#65288;(?<sp_level>[0-9]+)up&#65289;)?<br />\s*'.
                '&#12288;&#12288;&#12288;&#65306;(?<sp2_current>[0-9]+)&#65295;(?<sp2_max>[0-9]+)&#65288;Red&#65289;(?:&#65291;(?<sp2_reserve>[0-9]+))?(?:&#65288;(?<sp2_level>[0-9]+)up&#65289;)?<br />\s*'.
                '&#12288;Average Offensive Ability&#65306;(?<offensive_current>[0-9]+)(?:&#65288;(?:(?:(?<offensive_level>[0-9]+)up)|Details)&#65289;)?<br />\s*'.
                '&#12288;Average Defensive Ability&#65306;(?<defensive_current>[0-9]+)(?:&#65288;(?:(?:(?<defensive_level>[0-9]+)up)|Details)&#65289;)?<br />\s*'.
                '&#12288;Average Magic Ability&#65306;(?<magic_current>[0-9]+)(?:&#65288;(?:(?:(?<magic_level>[0-9]+)up)|Details)&#65289;)?<br />\s*'.
                '&#12288;Average Resistance Ability&#65306;(?<resistance_current>[0-9]+)(?:&#65288;(?:(?:(?<resistance_level>[0-9]+)up)|Details)&#65289;)?<br />\s*'.
                '&#12288;Average Speed Ability&#65306;(?<speed_current>[0-9]+)(?:&#65288;(?:(?:(?<speed_level>[0-9]+)up)|Details)&#65289;)?'.
                '(?:<br />\s*&#12288;(?<failed_status>Failed to appraise its status))?'.
                '(?:<br />\s*Skill<br />\s*&#12288;(?<skills>(?:&#12300;[0-9a-z -]+?(?: LV[0-9]+)?&#12301;)+)'.
                    '(?<skill_special>&#12300;n&#65285;I&#65309;W&#12301;)?'.
                    '(?:<br />\s*&#12288;Skill points&#65306;(?<skill_point>[0-9]+))?'.
                    '(?:<br />\s*Title<br />\s*&#12288;(?<titles>(?:&#12300;[0-9a-z -]+?&#12301;)+))?'.
                ')?'.
            ')?'.
            '&#12303;<br />\s*<br />\s*@isu',
            array($this, '_format_appraisal_'),
            $this->_body,
            -1,
            $nb_big
        );
        $nb += $nb_big;

        // Petits blocs (enveloppe)
        $this->_body = preg_replace_callback(
            /** @lang PhpRegExp */'@<br />\s*<br />\s*('.
                '(?:&#12302;[^.;]+?(?:&#12288;LV[0-9]+)?(?:&#12288;(?:Failed to appraise its status|[^.;]+?)(?:&#12288;)?)?&#12303;<br />\s*)+'.
            ')<br />@isu',
            function ($matches_enveloppe) use (&$nb) {
                $result = preg_replace_callback(
                    /** @lang PhpRegExp */'@&#12302;(?<species>[^.;]+?)(?:&#12288;LV(?<level>[0-9]+))?(?:&#12288;(?:(?<failed_name>Failed to appraise its status)|(?<name>[^.;]+?))(?:&#12288;)?)?&#12303;<br />\s*@isu',
                    array($this, '_format_appraisal_'),
                    $matches_enveloppe[1],
                    -1,
                    $nb_small
                );
                $nb += $nb_small;

                return $result;
            },
            $this->_body
        );

        echo_p($nb.' mises en forme pour "Appraisal"',1 );
    }
    /**
     * Formate UN "résultat" de la compétance "appraisal"
     *
     * @param string[] $matches Les données capturées (regex)
     *
     * @return string Le HTML du résultat, mis en forme (tableau)
     */
    private function _format_appraisal_ ($matches) {
        static $sections = array(
            'head',
            'status',
            'skill',
            'title',
            'failed',
        );

        $html = new DOMDocument('1.0', 'utf-8');

        $table = $html->createElement('table');
        $table->setAttribute('class', 'appraisal');
        foreach($sections as $section) {
            $node = call_user_func(array($this, __FUNCTION__.$section), $html, $matches);
            if(!is_null($node))
                $table->appendChild($node);
        }

        $html->appendChild($table);
        return '</p>'.trim($html->saveXML($html->documentElement)).'<p>';
    }
    /**
     * Formate la partie "Entête" (Espèce / Niveau / Nom) d'UN "résultat" de la compétance "appraisal"
     *
     * @param DOMDocument $html    Le document HTML de résultat.
     * @param string[]    $matches Les données capturées (regex)
     *
     * @return DOMNode|null Le noeud HTML des données. Null si les données sont indisponibles
     */
    private function _format_appraisal_head ($html, $matches) {
        $thead = $html->createElement('thead');
        $tr = $html->createElement('tr');
        $th = $html->createElement('th');
        $th->setAttribute('colspan', 4);

        $th->appendChild($html->createElement('span_manual', $matches['species']));

        if(!empty($matches['level']))
            $th->appendChild($html->createElement('span_manual', 'LV'.$matches['level']));

        if(!empty($matches['name']))
            $th->appendChild($html->createElement('span_manual', $matches['name']));

        $tr->appendChild($th);
        $thead->appendChild($tr);

        return $thead;
    }
    /**
     * Formate la partie "Status" (HP / MP / SP) d'UN "résultat" de la compétance "appraisal"
     *
     * @param DOMDocument $html    Le document HTML de résultat.
     * @param string[]    $matches Les données capturées (regex)
     *
     * @return DOMNode|null Le noeud HTML des données. Null si les données sont indisponibles
     */
    private function _format_appraisal_status ($html, $matches) {
        if(empty($matches['hp_current']))
            return null;

        static $types_info = array(
            'hp',
            'mp',
            'sp',
            'sp2',
            'offensive',
            'defensive',
            'magic',
            'resistance',
            'speed',
        );

        $infos = array();
        foreach($types_info as $type_info) {
            $infos[$type_info] =
                $matches[$type_info.'_current'].
                (!empty($matches[$type_info.'_max']) ? ' / '.$matches[$type_info.'_max'] : '').
                (!empty($matches[$type_info.'_reserve']) ? ' + '.$matches[$type_info.'_reserve'] : '').
                (!empty($matches[$type_info.'_level']) ? ' (level up: '.$matches[$type_info.'_level'].')' : '')
            ;
        }

        $tbody = $html->createElement('tbody');

        $tr = $html->createElement('tr');
        $th = $html->createElement('th', 'Status');
        $th->setAttribute('colspan', 4);
        $tr->appendChild($th);
        $tbody->appendChild($tr);

        $tr = $html->createElement('tr');
        $tr->appendChild($html->createElement('td', 'HP'));
        $tr->appendChild($html->createElement('td', $infos['hp']));
        $tr->appendChild($html->createElement('td', 'Average Offensive Ability'));
        $tr->appendChild($html->createElement('td', $infos['offensive']));
        $tbody->appendChild($tr);

        $tr = $html->createElement('tr');
        $tr->appendChild($html->createElement('td', 'MP'));
        $tr->appendChild($html->createElement('td', $infos['mp']));
        $tr->appendChild($html->createElement('td', 'Average Defensive Ability'));
        $tr->appendChild($html->createElement('td', $infos['defensive']));
        $tbody->appendChild($tr);

        $tr = $html->createElement('tr');
        $tr->appendChild($html->createElement('td', 'SP'));
        $tr->appendChild($html->createElement('td', $infos['sp']));
        $tr->appendChild($html->createElement('td', 'Average Magic Ability'));
        $tr->appendChild($html->createElement('td', $infos['magic']));
        $tbody->appendChild($tr);

        $tr = $html->createElement('tr');
        $tr->appendChild($html->createElement('td'));
        $tr->appendChild($html->createElement('td', $infos['sp2']));
        $tr->appendChild($html->createElement('td', 'Average Resistance Ability'));
        $tr->appendChild($html->createElement('td', $infos['resistance']));
        $tbody->appendChild($tr);

        $tr = $html->createElement('tr');
        $td = $html->createElement('td', '');
        $td->setAttribute('colspan', 2);
        $tr->appendChild($td);
        $tr->appendChild($html->createElement('td', 'Average Speed Ability'));
        $tr->appendChild($html->createElement('td', $infos['speed']));
        $tbody->appendChild($tr);

        return $tbody;
    }
    /**
     * Formate la partie "Skill" (Compétances / Niveau) d'UN "résultat" de la compétance "appraisal"
     *
     * @param DOMDocument $html    Le document HTML de résultat.
     * @param string[]    $matches Les données capturées (regex)
     *
     * @return DOMNode|null Le noeud HTML des données. Null si les données sont indisponibles
     */
    private function _format_appraisal_skill ($html, $matches) {
        if(empty($matches['skills']))
            return null;

        $tbody = $html->createElement('tbody');

        $tr = $html->createElement('tr');
        $th = $html->createElement('th', 'Skill');
        $th->setAttribute('colspan', 4);
        $tr->appendChild($th);
        $tbody->appendChild($tr);

        if(!preg_match_all('@&#12300;(?<name>[0-9a-z -]+?)(?:LV(?<level>[0-9]+))?&#12301;@isu', $matches['skills'], $skills, PREG_SET_ORDER))
            return $tbody;          // Ici on retourne bien le tbody (incomplet) car pas vraiment normal

        if(!empty($matches['skill_special']))
            $skills[] = array('name' => '[n ％ I ＝ W]');

        static $nbCol = 2;
        $nbLig = ceil(count($skills) / (double)$nbCol);
        for($lig = 0; $lig < $nbLig; $lig++) {
            $tr = $html->createElement('tr');

            for($col = 0; $col < $nbCol; $col++) {
                if(!empty($skills[$lig + $nbLig * $col])) {
                    $td = $html->createElement('td', $skills[$lig + $nbLig * $col]['name']);
                    if(empty($skills[$lig + $nbLig * $col]['level']))
                        $td->setAttribute('colspan', 2);
                    $tr->appendChild($td);

                    if(!empty($skills[$lig + $nbLig * $col]['level']))
                        $tr->appendChild($html->createElement('td', 'LV'.$skills[$lig + $nbLig * $col]['level']));
                }
                else {
                    $td = $html->createElement('td');
                    if(empty($skills[$lig + $nbLig * $col]['level']))
                        $td->setAttribute('colspan', 2);
                    $tr->appendChild($td);
                }
            }

            $tbody->appendChild($tr);
        }

        if(!empty($matches['skill_point'])) {
            $tr = $html->createElement('tr');
            $td = $html->createElement('td', 'Skill Points: '.$matches['skill_point']);
            $td->setAttribute('colspan', 4);
            $tr->appendChild($td);

            $tbody->appendChild($tr);
        }

        return $tbody;
    }
    /**
     * Formate la partie "Title" (Titres) d'UN "résultat" de la compétance "appraisal"
     *
     * @param DOMDocument $html    Le document HTML de résultat.
     * @param string[]    $matches Les données capturées (regex)
     *
     * @return DOMNode|null Le noeud HTML des données. Null si les données sont indisponibles
     */
    private function _format_appraisal_title ($html, $matches) {
        if(empty($matches['titles']))
            return null;

        $tbody = $html->createElement('tbody');

        $tr = $html->createElement('tr');
        $th = $html->createElement('th', 'Title');
        $th->setAttribute('colspan', 4);
        $tr->appendChild($th);
        $tbody->appendChild($tr);

        if(!preg_match_all('@&#12300;(?<name>[0-9a-z -]+?)&#12301;@isu', $matches['titles'], $titles, PREG_SET_ORDER))
            return $tbody;          // Ici on retourne bien le tbody (incomplet) car pas vraiment normal

        static $nbCol = 4;
        $nbLig = ceil(count($titles) / (double)$nbCol);
        for($lig = 0; $lig < $nbLig; $lig++) {
            $tr = $html->createElement('tr');

            for($col = 0; $col < $nbCol; $col++) {
                if(!empty($titles[$lig + $nbLig * $col]))
                    $td = $html->createElement('td', $titles[$lig + $nbLig * $col]['name']);
                else
                    $td = $html->createElement('td');

                $tr->appendChild($td);
            }

            $tbody->appendChild($tr);
        }

        return $tbody;
    }
    /**
     * En cas de "failed to apparaised", insère une partie l'indiquant
     *
     * @param DOMDocument $html    Le document HTML de résultat.
     * @param string[]    $matches Les données capturées (regex)
     *
     * @return DOMNode|null Le noeud HTML des données. Null si les données sont indisponibles
     */
    private function _format_appraisal_failed ($html, $matches) {
        static $parts = array(
            'name',
            'status',
        );

        $tbody = $html->createElement('tbody');

        $hasFailed = false;
        foreach($parts as $part) {
            if(!empty($matches['failed_'.$part])) {
                $hasFailed = true;

                $tr = $html->createElement('tr');
                $td = $html->createElement('td', $matches['failed_'.$part]);
                $td->setAttribute('class', 'appraisal-fail');
                $td->setAttribute('colspan', 4);
                $tr->appendChild($td);

                $tbody->appendChild($tr);
            }
        }

        if(!$hasFailed)
            return null;

        return $tbody;
    }

    /**
     * Formate les paragraphes et les dialogues
     */
    private function _format_paragraphAndDialogs () {
        if(preg_match('@<div>\s*<br />\s*</div>@i', $this->_body))
            $this->_format_paragraphAndDialogs_div();
        else
            $this->_format_paragraphAndDialogs_normal();
    }
    /**
     * Formate les paragraphes et les dialogues avec une structure basée sur des <div>
     */
    private function _format_paragraphAndDialogs_div () {
        // Paragraphes
        $this->_body = preg_replace('@</div>\s*(?:<div>\s*<br />\s*</div>\s*){3,}\s*<div>\s*@i', '</p><p>***</p><p>', $this->_body);        // Grands changements de paragraphe
        $this->_body = preg_replace('@</div>\s*<div>\s*<br />\s*</div>\s*<div>\s*@i', '</p><p>', $this->_body, -1, $nb);                                    // Changements de paragraphe
        $this->_body = preg_replace('@</div>\s*<div>\s*@i', ' ', $this->_body);                                                             // Faux retours à la ligne

        // Dialogues
        $this->_body = preg_replace('@&#12301;\s*&#12300;@i', '"<br_manual>"', $this->_body);
        $this->_body = preg_replace('@&#1230[01];@i', '"', $this->_body);
    }
    /**
     * Formate les paragraphes et les dialogues avec une structure "normale" (pas de div)
     */
    private function _format_paragraphAndDialogs_normal () {
        // Dialogues
        $this->_body = preg_replace('@&#12300;(.+?)&#12301;<br />@i', '"$1"<br_manual>', $this->_body);

        // Paragraphes
        $this->_body = preg_replace('@(?:<br />\s*){3,}@i', '</p><p>***</p><p>', $this->_body);         // Grands changements de paragraphe
        $this->_body = preg_replace('@(?:<br />\s*){2}@i', '</p><p>', $this->_body);                   // Changements de paragraphe
        $this->_body = preg_replace('@<br />\s*@i', ' ', $this->_body);                                // Faux retours à la ligne
    }

    /**
     * Sauvegarde le chapitre sous la forme d'un fichier HTML
     */
    public function save () {
        echo_p('Sauvegarde HTML du chapitre');

        echo_p('Création racine page', 1);
        $xml = new DOMDocument('1.0', 'utf-8');

        $html = $xml->createElementNS('http://www.w3.org/1999/xhtml', 'html');
        $html->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:epub', 'http://www.idpf.org/2007/ops');
        $html->setAttribute('lang', 'en');
        $html->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:lang', 'en');

        $html->appendChild($this->_save_head($xml));
        $html->appendChild($this->_save_body($xml));

        $xml->appendChild($html);
        //$xml->save(realpath(self::DIR_TEXT.$this->_getFilename()));

        $formatter = new DOMFormatter();
        $formatter->setStartIndentationLevel(-1);
        file_put_contents(self::DIR_TEXT.$this->_getFilename(), $formatter->formatXML($xml));
    }
    /**
     * Crée la partie entête de la page HTML du chapitre
     *
     * @param DOMDocument $xml Le XML du HTML du chapitre
     *
     * @return DOMNode L'élément entête
     */
    private function _save_head ($xml) {
        echo_p('Création entête page', 1);
        $head = $xml->createElement('head');

        $head->appendChild($xml->createElement('title', $this->_number->long().' - '.htmlentities($this->_title)));

        $meta = $xml->createElement('meta');
        $meta->setAttribute('content', 'text/html; charset=utf-8');
        $meta->setAttribute('http-equiv', 'Content-Type');
        $head->appendChild($meta);

        $link = $xml->createElement('link');
        $link->setAttribute('href', '../style/text.css');
        $link->setAttribute('rel', 'stylesheet');
        $link->setAttribute('type', 'text/css');
        $head->appendChild($link);

        return $head;
    }
    /**
     * Crée la partie corps de la page HTML du chapitre
     *
     * @param DOMDocument $xml Le XML du HTML du chapitre
     *
     * @return DOMNode L'élément corps
     */
    private function _save_body ($xml) {
        echo_p('Création corps page', 1);
        $body = $xml->createElement('body');
        $body->setAttribute('class', 'calibre');
        $body->setAttribute('id', $this->_getIdBody());

        $body->appendChild($xml->createElement('h2', $this->_number->long().' - '.htmlentities($this->_title)));

        // Construction DOM temporaire à partir du contenu HTML
        $content = new DOMDocument('1.0', 'utf-8');
        $content->loadXML('<html lang="en"><body>'.$this->_body.'</body></html>');

        $xpath = new DOMXPath($content);
        $root = $xpath->query('/html/body')->item(0);

        // Recopie du DOM temporaire dans la page de sortie
        foreach($root->childNodes as $child)
            $body->appendChild($xml->importNode($child, true));

        return $body;
    }

    /**
     * Référence le fichier HTML du chapitre dans le fichier de structure
     */
    public function addToStructure () {
        echo_p('Ajout à la structure du livre');

        echo_p('Chargement fichier structure', 1);
        $xml = new DOMDocument('1.0', 'utf-8');
        $xml->preserveWhiteSpace = false;
        $xml->formatOutput = true;

        if(!$xml->load(realpath( self::FILE_STRUCTURE)))
            echo_p('Echec chargement XML', 2, true);

        $this->_addToStructure_manifest($xml);
        $this->_addToStructure_spine($xml);

//        $xml->save(realpath(self::FILE_STRUCTURE));
        $formatter = new DOMFormatter();
        file_put_contents(self::FILE_STRUCTURE, $formatter->formatXML($xml));
    }
    /**
     * Enregistre le chemin & type du chapitre dans le fichier de structure
     *
     * @param DOMDocument $xml Le XML du fichier de structure.
     */
    private function _addToStructure_manifest(&$xml) {
        echo_p('Enregistrement chemin & type du chapitre', 1);
        $xpath = new DOMXPath($xml);
        $xpath->registerNamespace('opf', 'http://www.idpf.org/2007/opf');

        $manifest = $xpath->query('/opf:package/opf:manifest');
        if($manifest === false || $manifest->length == 0)
            echo_p('Impossible de trouver la balise <manifest>', 2, true);
        $manifest = $manifest->item(0);

        $item = $xml->createElement('item');
        $item->setAttribute('href', self::DIRNAME_TEXT.$this->_getFilename());
        $item->setAttribute('id', $this->_getIdPage());
        $item->setAttribute('media-type', 'application/xhtml+xml');

        $exist = $xpath->query('./opf:item[@id="'.$this->_getIdPage().'"]', $manifest);
        if($exist === false)
            echo_p('Echec recherche balise <item>', 2, true);

        if($exist->length > 0) {
            echo_p('Balise existante => remplacement', 2);
            $manifest->replaceChild($item, $exist->item(0));
        }
        else {
            echo_p('Balise inexistante => insertion', 2);
            $manifest->appendChild($item);
        }
    }
    /**
     * Ajoute l'id du fichier à liste des documents de la structure
     *
     * @param DOMDocument $xml Le XML du fichier de structure.
     */
    private function _addToStructure_spine(&$xml) {
        echo_p('Ajout id du fichier du chapitre', 1);
        $xpath = new DOMXPath($xml);
        $xpath->registerNamespace('opf', 'http://www.idpf.org/2007/opf');

        $spine = $xpath->query('/opf:package/opf:spine');
        if($spine === false || $spine->length == 0)
            echo_p('Impossible de trouver la balise <spine>', 2, true);
        $spine = $spine->item(0);

        $itemref = $xml->createElement('itemref');
        $itemref->setAttribute('idref', $this->_getIdPage());

        $exist = $xpath->query('./opf:itemref[@idref="'.$this->_getIdPage().'"]', $spine);
        if($exist === false)
            echo_p('Echec recherche balise <itemref>', 2, true);

        if($exist->length > 0) {
            echo_p('Balise existante => remplacement', 2);
            $spine->replaceChild($itemref, $exist->item(0));
        }
        else {
            echo_p('Balise inexistante => insertion', 2);
            $spine->appendChild($itemref);
        }
    }

    /**
     * Ajoute le chapitre au sommaire.
     */
    public function addToSummary() {
        echo_p('Ajout au sommaire du livre');

        echo_p('Chargement fichier sommaire', 1);
        $xml = new DOMDocument('1.0', 'utf-8');
        $xml->preserveWhiteSpace = false;
        $xml->formatOutput = true;

        if(!$xml->load(realpath( self::FILE_SUMMARY)))
            echo_p('Echec chargement XML', 2, true);

        $xpath = new DOMXPath($xml);
        $xpath->registerNamespace('ncx', 'http://www.daisy.org/z3986/2005/ncx/');

        $navMap = $xpath->query('/ncx:ncx/ncx:navMap');
        if($navMap === false || $navMap->length == 0)
            echo_p('Impossible de trouver la balise <navMap>', 2, true);
        $navMap = $navMap->item(0);

        $navPoint = $xml->createElement('navPoint');
        $navPoint->setAttribute('class', 'chapter');
        $navPoint->setAttribute('id', $this->_getIdSummary());
        $navPoint->setAttribute('playOrder', $this->_number->raw());

        $navLabel = $xml->createElement('navLabel');

        $text = $xml->createElement('text', $this->_number->long().' - '.self::_escapeMinimalXML($this->_title));
        $navLabel->appendChild($text);

        $navPoint->appendChild($navLabel);

        $content = $xml->createElement('content');
        $content->setAttribute('src', self::DIRNAME_TEXT.$this->_getFilename().'#'.$this->_getIdBody());
        $navPoint->appendChild($content);

        $exist = $xpath->query('./ncx:navPoint[@id="'.$this->_getIdSummary().'"]', $navMap);
        if($exist === false)
            echo_p('Echec recherche balise <navPoint>', 2, true);

        if($exist->length > 0) {
            echo_p('Balise existante => remplacement', 2);
            $navMap->replaceChild($navPoint, $exist->item(0));
        }
        else {
            echo_p('Balise inexistante => insertion', 2);
            $navMap->appendChild($navPoint);
        }

        $formatter = new DOMFormatter();
        file_put_contents(self::FILE_SUMMARY, $formatter->formatXML($xml));
    }

    /**
     * L'id de la page du chapitre.
     *
     * @return string L'id
     */
    private function _getIdPage() {
        return 'page-'.$this->_number->short();
    }
    /**
     * L'id de sommaire du chapitre.
     *
     * @return string L'id
     */
    private function _getIdSummary() {
        return 'num-'.$this->_number->short();
    }
    /**
     * L'id du corps la page du chapitre.
     *
     * @return string L'id
     */
    private function _getIdBody() {
        return 'body-'.$this->_number->short();
    }
    /**
     * @return string Le nom du fichier du chapitre
     */
    private function _getFilename() {
        return 'part0'.$this->_number->file().'.html';
    }

    /**
     * Remplace les caractères "width" par leur équivalent "normal"
     *
     * @param string $str La chaine à traiter
     *
     * @return string La chaine une fois traitée
     */
    private static function _replaceWidthChars($str) {
        return preg_replace_callback(
            '@[\x{FF01}-\x{FF5E}]@u',
            function ($matches) {
                return chr(mb_ord($matches[0], 'utf-8') - mb_ord('！', 'utf-8') + mb_ord('!', 'utf-8'));
            },
            $str
        );
    }
    /**
     * Echape le minimum des caractères XML (&)
     *
     * @param string $str La chaine à traiter
     *
     * @return string La chaine une fois traitée
     */
    private static function _escapeMinimalXML($str) {
        return str_replace('&', '&amp;', html_entity_decode($str));
    }
}
/**
 * Le numéro d'un chapitre
 */
class ChapterNumber {
    /**
     * @var string La numéro du chapitre
     */
    private $_number;

    /**
     * Nouveau numéro de chapitre
     *
     * @param $number
     */
    public function __construct ($number) {
        $this->setRaw($number);
    }

    /**
     * Le numéro du chapitre, brut (non modifié)
     *
     * @return string Le numéro du chapitre
     */
    public function raw() {
        return $this->_number;
    }
    /**
     * Modifie le numéro du chapitre
     *
     * @param string $number Le nouveau numéro de chapitre
     *
     * @return $this
     */
    public function setRaw($number) {
        $this->_number = $number;
        return $this;
    }

    /**
     * La version courte du numéro de chapitre
     *
     * @return string La version courte
     */
    public function short() {
        return str_replace('.', '-', $this->raw());
    }
    /**
     * La version longue du numéro de chapitre
     *
     * @return string La version longue
     */
    public function long() {
        $pos = strpos($this->raw(), '.');
        if($pos === false)
            return str_pad($this->raw(), 3, '0', STR_PAD_LEFT);
        else {
            return
                str_pad(substr($this->raw(), 0, $pos), 3, '0', STR_PAD_LEFT).
                '.'.
                substr($this->raw(), $pos + 1);
        }
    }
    /**
     * La version "fichier" du numéro de chapitre
     *
     * @return string La version "fichier"
     */
    public function file() {
        return str_replace('.', '-', $this->long());
    }
}

/**
 * Classe pour la mise en forme d'un document DOM
 */
class DOMFormatter {
    /**
     * @var int Nombre d'espace dans une tabulation. 0 pour insérer la tabulation elle-même
     */
    private $_tabSize = 4;
    /**
     * @var int Le niveau d'identation de départ. Peut être négatif
     */
    private $_startIndentationLevel = 0;

    /**
     * Nouvelle mise en forme
     */
    public function __construct () {}

    /**
     * Taille (en nombre d'espace) d'une tabulation
     *
     * 0 si le caractère <tabulation> est lui-même inséré.
     *
     * @return int La taille
     */
    public function getTabSize() {
        return $this->_tabSize;
    }
    /**
     * Modifie la taille (en nombre d'espace) d'une tabulation
     *
     * 0 pour insérer le caractère <tabulation> lui-même.
     *
     * @param int $tabSize La nouvelle taille
     *
     * @return $this
     */
    public function setTabSize($tabSize) {
        $this->_tabSize = $tabSize;
        return $this;
    }
    /**
     * Taille (en nombre d'espace) d'une tabulation
     *
     * 0 si le caractère <tabulation> est lui-même inséré.
     *
     * @return int La taille
     */

    /**
     * Niveau d'identation de départ
     *
     * Peut être négatif, par exemple pour indiquer l'"annulation" de l'indentation des X premiers niveaux.
     *
     * @return int Le niveau d'indentation
     */
    public function getStartIndentationLevel() {
        return $this->_startIndentationLevel;
    }
    /**
     * Modifie le niveau d'identation de départ
     *
     * Peut être négatif, par exemple pour "annuler" l'indentation des X premiers niveaux.
     *
     * @param int $startIndentationLevel La nouveau niveau d'indentation
     *
     * @return $this
     */
    public function setStartIndentationLevel($startIndentationLevel) {
        $this->_startIndentationLevel = $startIndentationLevel;
        return $this;
    }

    /**
     * Met en forme un document DOM au format HTML
     *
     * @param DOMDocument $dom Le document DOM à mettre en forme
     *
     * @return string Le code HTML mis en forme
     */
    public function formatHTML ($dom) {
        return $this->_format($dom, false);
    }
    /**
     * Met en forme un document DOM au format XML
     *
     * @param DOMDocument $dom Le document DOM à mettre en forme
     *
     * @return string Le code XML mis en forme
     */
    public function formatXML ($dom) {
        return $this->_format($dom, true);
    }

    /**
     * Met en forme un document DOM
     *
     * @param DOMDocument $dom Le document DOM à mettre en forme
     * @param bool        $xml Générer l'entête XML ?
     *
     * @return string Le code mis en forme
     */
    private function _format ($dom, $xml) {
        $this->_dom = $dom;
        $code = '';

        $code .= $this->_format_xmlHeader($dom)."\n";
        $code .= $this->_format_node($dom, $dom->documentElement, false, $this->_startIndentationLevel, array());

        return $code;
    }
    /**
     * Génère l'entête XML d'un document DOM
     *
     * @param DOMDocument $dom Le document DOM.
     *
     * @return string L'entête XML
     */
    private function _format_xmlHeader ($dom) {
        return '<?xml version="'.$dom->xmlVersion.'" encoding="'.$dom->xmlEncoding.'" ?>';
    }
    /**
     * Met en forme un noeud DOM.
     *
     * @param DOMDocument $dom                Le document DOM à mettre en forme
     * @param DOMNode     $node               Le noeud à mettre en forme
     * @param bool        $inline             Noeud à l'intérieur d'une structure "une seule ligne" ?
     * @param int         $indentLevel        Le niveau d'indentation actuelle
     * @param DOMNode[]   $declaredNamespaces Les espaces de nom déjà déclarés
     *
     * @return string Le contenu du noeud
     */
    private function _format_node ($dom, $node, $inline, $indentLevel, $declaredNamespaces) {
        $node->normalize();
        if($node->nodeType === XML_TEXT_NODE)
            return $node->textContent;

        $hasTextChild = false;
        foreach($node->childNodes as $child) {
            if($child->nodeType == XML_TEXT_NODE) {
                $hasTextChild = true;
                break;
            }
        }

        $code = '';

        if(!$inline)
            $code .= $this->_getIndent($indentLevel);
        $code .= '<'.$node->nodeName;
        $code .= $this->_format_node_attributes($dom, $node, $declaredNamespaces);
        if(!$node->hasChildNodes())
            $code .= ' /';
        $code .= '>';

        if(!$node->hasChildNodes())
            return $code;

        if(!$hasTextChild)
            $code .= "\n";

        foreach($node->childNodes as $child) {
            $code .= $this->_format_node($dom, $child, $inline || $hasTextChild, $indentLevel + 1, $declaredNamespaces);
            if(!$inline && !$hasTextChild)
                $code .= "\n";
        }

        if(!$inline && !$hasTextChild)
            $code .= $this->_getIndent($indentLevel);
        $code .= '</'.$node->nodeName.'>';

        return $code;
    }
    /**
     * Met en forme les attributs d'un noeud DOM.
     *
     * @param DOMDocument $dom                Le document DOM à mettre en forme
     * @param DOMNode     $node               Le noeud à mettre en forme
     * @param DOMNode[]   $declaredNamespaces Les espaces de nom déjà déclarés
     *
     * @return string Le contenu du noeud
     */
    private function _format_node_attributes ($dom, $node, &$declaredNamespaces = array()) {
        $code = '';

        $xpath = new DOMXPath($dom);

        /** @var DOMAttr $attribut */
        foreach($node->attributes as $attribut)
            $code .= ' '.(empty($attribut->prefix) ? '' : $attribut->prefix.':').$attribut->name.'="'.str_replace('"', '&quot;', $attribut->value).'"';

        $namespaces = $xpath->query('namespace::*', $node);
        /** @var DOMNode $namespace */
        foreach($namespaces as $namespace) {
            foreach($declaredNamespaces as $declaredNamespace) {
                if($declaredNamespace->nodeName == $namespace->nodeName && $declaredNamespace->nodeValue == $namespace->nodeValue)
                    continue 2;
            }

            $code .= ' '.$namespace->nodeName.'="'.$namespace->nodeValue.'"';
            $declaredNamespaces[] = $namespace;
        }

        return $code;
    }

    /**
     * L'indentation réelle à partir de son niveau
     *
     * @param int $indentLevel Le niveau d'indentation voulu
     *
     * @return string L'indentation.
     */
    private function _getIndent($indentLevel) {
        if($indentLevel > 0)
            return str_repeat($this->_tabSize == 0 ? "\t" : str_repeat(' ', $this->_tabSize), $indentLevel);

        return '';
    }
}

/**
 * Affiche un message (echo) avec un retour auto à la ligne à la fin
 *
 * @param string $msg   Le message
 * @param int    $nbTab Le nombre de tabulation à insérer devant
 * @param bool   $end   Stopper l'exécution ?
 */
function echo_p($msg, $nbTab = 0, $end = false) {
    echo str_repeat("\t", $nbTab).$msg."\n";
    if($end)
        exit(1);
}