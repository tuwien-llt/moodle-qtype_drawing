<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Stream wrapper for binary blob data.
 *
 * @package    qtype_drawing
 * @copyright  ETH Zurich LET <amr.hourani@id.ethz.ch>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qtype_drawing\local;

/**
 * Stream wrapper for binary blob data.
 *
 * Provides stream wrapper functionality for reading and writing binary blob data.
 */
class drawing_blob_data_as_file_stream {
    /** @var int Position in the blob data stream */
    private static $blobdataposition = 0;
    /** @var string The binary blob data */
    public static $blobdatastream = '';
    /** @var mixed Context */
    public $context;

    /**
     * Open the stream.
     *
     * @param string $path
     * @param string $mode
     * @param int $options
     * @param string $openedpath
     * @return bool
     */
    public static function stream_open($path, $mode, $options, &$openedpath) {
        static::$blobdataposition = 0;
        return true;
    }

    /**
     * Seek in the stream.
     *
     * @param int $seekoffset
     * @param int $seekwhence
     * @return bool
     */
    public static function stream_seek($seekoffset, $seekwhence) {
        $blobdatalength = strlen(static::$blobdatastream);
        switch ($seekwhence) {
            case SEEK_SET:
                $newblobdataposition = $seekoffset;
                break;
            case SEEK_CUR:
                $newblobdataposition = static::$blobdataposition + $seekoffset;
                break;
            case SEEK_END:
                $newblobdataposition = $blobdatalength + $seekoffset;
                break;
            default:
                return false;
        }
        if (($newblobdataposition >= 0) && ($newblobdataposition <= $blobdatalength)) {
            static::$blobdataposition = $newblobdataposition;
            return true;
        } else {
            return false;
        }
    }

    /**
     * Get the current position in the stream.
     *
     * @return int
     */
    public static function stream_tell() {
        return static::$blobdataposition;
    }

    /**
     * Read from the stream.
     *
     * @param int $readbuffersize
     * @return string
     */
    public static function stream_read($readbuffersize) {
        $readdata = substr(static::$blobdatastream, static::$blobdataposition, $readbuffersize);
        static::$blobdataposition += strlen($readdata);
        return $readdata;
    }

    /**
     * Write to the stream.
     *
     * @param string $writedata
     * @return int
     */
    public static function stream_write($writedata) {
        $writedatalength = strlen($writedata);
        static::$blobdatastream = substr(static::$blobdatastream, 0, static::$blobdataposition) .
                $writedata . substr(static::$blobdatastream, static::$blobdataposition += $writedatalength);
        return $writedatalength;
    }

    /**
     * Check if we've reached the end of the stream.
     *
     * @return bool
     */
    public static function stream_eof() {
         return static::$blobdataposition >= strlen(static::$blobdatastream);
    }
}
