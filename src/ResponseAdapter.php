<?php
namespace WP\Pdf2web;

use Pdf2web\Doc;
use Pdf2web\File;
use Pdf2web\Storage\FileInfo;
use Pdf2web\Storage\IDocContentFilenameMapper;
use Pdf2web\Storage\IStorageIO;
use Pdf2web\Storage\PopplerFilenameMapper;
use Pdf2web\Storage\StorageItemNotExistsException;
use Pdf2web\Storage\StorageReadException;
use WP\Net\Http\IResponse;

class ResponseAdapter
{
    /** @var IStorageIO */
    private $_io;
    /** @var IDocContentFilenameMapper */
    private $_mapper;

    /**
     * ResponseAdapter constructor.
     * @param IStorageIO $storageIO
     * @param IDocContentFilenameMapper $mapper
     */
    public function __construct(IStorageIO $storageIO, IDocContentFilenameMapper $mapper = null) {
        $this->_io = $storageIO;
        $this->_mapper = is_null($mapper) ? new PopplerFilenameMapper() : $mapper;
    }

    /**
     * @param File $file
     * @param IResponse $response
     * @param bool $asAttachment
     * @throws StorageItemNotExistsException
     * @throws StorageReadException
     * @throws \Exception
     */
    public function fileToResponse(File $file, IResponse $response, $asAttachment = true) {
        /** @var FileInfo $fileInfo */
        $fileInfo = null;
        $this->_tryInHttpContext($response, function() use ($file, &$fileInfo) {
            $fileInfo = $this->_io->getFileInfo($file);
        });
        $headers = [
            'Content-Length' => $fileInfo->size,
            'Content-Type' => 'application/pdf',
        ];
        if ($asAttachment) {
            $attachmentName = "doc.pdf";
            $headers['Content-Disposition'] = sprintf('attachment; filename="%s"', $attachmentName);
        }
        $this->_httpSend($response, $headers, function($outStream) use ($file) {
            $this->_io->readFile($file, $outStream);
        });
    }

    /**
     * @param Doc $doc
     * @param int $pageNum
     * @param IResponse $response
     */
    public function docPageBackgroundToResponse(Doc $doc, $pageNum, IResponse $response) {
        $this->_httpSend(
            $response,
            ['Content-Type' => 'image/png'],
            function($outStream) use ($doc, $pageNum) {
                $reader = $this->_io->getReadContextFor($doc);
                try {
                    return $reader->readDocData($this->_mapper->getBgrFilename($pageNum), $outStream);
                } finally {
                    $reader->close();
                }
            }
        );
    }

    /**
     * @param IResponse $response
     * @param \Closure $callback
     * @throws StorageItemNotExistsException
     * @throws StorageReadException
     * @throws \Exception
     */
    private function _tryInHttpContext(IResponse $response, \Closure $callback) {
        try {
            $callback();
        } catch (StorageItemNotExistsException $e) {
            $response->sendStatus(404);
            $response->write('404: Not Found');
            throw $e;
        } catch (StorageReadException $e) {
            $response->sendStatus(403);
            $response->write('403: Forbidden');
            throw $e;
        } catch (\Exception $e) {
            $response->sendStatus(500);
            $response->write('500: Server error');
            throw $e;
        }
    }

    /**
     * @param IResponse $response
     * @param array $headers
     * @param \Closure $callback
     */
    private function _httpSend(IResponse $response, $headers, \Closure $callback) {
        foreach ($headers as $name => $val) {
            $response->setHeader($name, $val);
        }
        $toStream = fopen('php://output', 'wb');
        try {
            $this->_tryInHttpContext($response, function() use ($callback, $toStream) {
                $callback($toStream);
            });
        } finally {
            fclose($toStream);
        }
    }
}
