<?php

// Parse the XML, keeping only those objects that appear in the AAC JSON
//

define( 'WS', DIRECTORY_SEPARATOR );

define( 'DIR_ROOT',     __DIR__               . WS );
define( 'DIR_DATA',     DIR_ROOT . 'data'     . WS );
define( 'DIR_VENDOR',   DIR_ROOT . 'vendor'   . WS );

require DIR_VENDOR .'autoload.php';

// header("Content-Type: text/plain");
// header("Content-Type: application/json");

ini_set('memory_limit', '-1');
set_time_limit(0);

define( 'FILE_AAC_OLD', DIR_DATA . 'aac.json' );
define( 'FILE_AAC_NEW', DIR_DATA . 'aac-new.json' );
define( 'FILE_EMU_OLD', DIR_DATA . 'DagwoodEmuRecords.xml' );
define( 'FILE_EMU_NEW', DIR_DATA . 'emu.xml' );
define( 'FILE_IDS', DIR_DATA . 'ids.json' );


// Create a file that contains our desired EMU irns (ids)
// We will use this to parse the XML for only the records we want
if( !file_exists( FILE_IDS ) ) {

    $file = file_get_contents( FILE_AAC_OLD );
    $json = json_decode( $file, true );

    $ids = array_keys( $json['data'] );
    $out = json_encode( $ids );

    file_put_contents( FILE_IDS, $out );

    header("Content-Type: application/json");
    echo $out;
    exit;

}

class ObjectElement extends SimpleXMLElement {

    // Shortcut for getting value of <meta> w/ emu_name attribute
    public function meta( $emu_name ) {
        $emu_name = $this->xpath("/*/meta[@emu_name='{$emu_name}']");
        $emu_name = array_shift( $emu_name );
        $emu_name = $emu_name ? (string) $emu_name : null;
        return $emu_name;
    }

}

// We will use this to parse <object> in the XML
class ObjectIterator extends XMLElementIterator {
    const ELEMENT_NAME = 'object';

    public function __construct(XMLReader $reader) {
        parent::__construct($reader, self::ELEMENT_NAME);
    }

    /**
     * @return SimpleXMLElement
     */
    public function current() {
        // http://stackoverflow.com/questions/2970602/php-how-to-handle-cdata-with-simplexmlelement
        return simplexml_load_string($this->reader->readOuterXml(), "ObjectElement", LIBXML_NOCDATA);
    }
}

// http://stackoverflow.com/questions/4778865/php-simplexml-addchild-with-another-simplexmlelement
function simplexml_append(SimpleXMLElement $to, SimpleXMLElement $from) {
    $toDom = dom_import_simplexml($to);
    $fromDom = dom_import_simplexml($from);
    $toDom->appendChild($toDom->ownerDocument->importNode($fromDom, true));
}

// Parse the original EMU XML, keep only objects w/ irns that match AAC's
// We are doing this mainly to speed up future processing
if( !file_exists( FILE_EMU_NEW ) ) {

    // We are going to output the results as we go along
    header("Content-Type: text/plain");

    // Start output buffering if it's disabled
    // http://php.net/manual/en/function.ob-get-level.php
    if( !ob_get_level() ) {
        ob_start();
    }

    $reader = new XMLReader();
    $reader->open( FILE_EMU_OLD );

    // Inefficient, but we need to do an Xpath query a la in_array for irns
    $ids = file_get_contents( FILE_IDS );
    $ids = json_decode( $ids );

    // Uncomment for testing
    // $ids = array_slice( $ids, 0, 5 );

    /* @var $users XMLReaderNode[] - iterate over all <object> elements */
    $objects = new ObjectIterator($reader);

    // This variable will be added to the response
    $filtered = array();

    foreach( $objects as $object ) {

        if( in_array( $object->meta('irn'), $ids ) ) {

            $filtered[] = $object;

            echo $object->meta('TitAccessionNo') . PHP_EOL;

            ob_flush();
            flush();

            // Uncomment for testing
            // if( $objects->key() > 5 ) break;

        }

    }

    // Build output to match the Dagwood export file
    $result = new SimpleXMLElement('<response></response>');
    $status = $result->addChild( 'status', 'ok' );
    $total = $result->addChild( 'totalItems', count($filtered) );

    foreach( $filtered as $object ) {
        simplexml_append( $result, $object );
    }

    // Possible but unnecessary improvement:
    // http://stackoverflow.com/questions/1191167/format-output-of-simplexml-asxml
    $out = $result->asXML();

    file_put_contents( FILE_EMU_NEW, $out );

    echo "Done.";

    ob_end_flush();

}