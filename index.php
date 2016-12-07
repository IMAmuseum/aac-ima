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
