<?php

namespace GoetasWebservices\XML\SOAPReader;

use GoetasWebservices\XML\SOAPReader\Soap\AbstractHeader;
use GoetasWebservices\XML\SOAPReader\Soap\AbstractMessage;
use GoetasWebservices\XML\SOAPReader\Soap\Body;
use GoetasWebservices\XML\SOAPReader\Soap\Fault;
use GoetasWebservices\XML\SOAPReader\Soap\Header;
use GoetasWebservices\XML\SOAPReader\Soap\HeaderFault;
use GoetasWebservices\XML\SOAPReader\Soap\Operation;
use GoetasWebservices\XML\SOAPReader\Soap\OperationMessage;
use GoetasWebservices\XML\SOAPReader\Soap\Service;
use GoetasWebservices\XML\WSDLReader\DefinitionsReader;
use GoetasWebservices\XML\WSDLReader\Events\Binding\FaultEvent;
use GoetasWebservices\XML\WSDLReader\Events\Binding\MessageEvent as BindingOperationMessageEvent;
use GoetasWebservices\XML\WSDLReader\Events\Binding\OperationEvent as BindingOperationEvent;
use GoetasWebservices\XML\WSDLReader\Events\BindingEvent;
use GoetasWebservices\XML\WSDLReader\Events\Service\PortEvent;
use GoetasWebservices\XML\WSDLReader\Wsdl\Binding;
use GoetasWebservices\XML\WSDLReader\Wsdl\Message;
use GoetasWebservices\XML\WSDLReader\Wsdl\Message\Part;
use GoetasWebservices\XML\WSDLReader\Wsdl\Service\Port;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SoapReader implements EventSubscriberInterface
{
    const SOAP_NS = 'http://schemas.xmlsoap.org/wsdl/soap/';
    const SOAP12_NS = 'http://schemas.xmlsoap.org/wsdl/soap12/';


    public static function getSubscribedEvents()
    {
        return [
            'service.port' => 'onServicePort',
            'binding' => 'onBinding',
            'binding.operation' => 'onBindingOperation',
            'binding.operation.message' => 'onBindingOperationMessage',
            'binding.operation.fault' => 'onBindingOperationFault'
        ];
    }

    /**
     *
     * @var Service[]
     */
    protected $servicesByBinding = array();

    /**
     *
     * @var Service[]
     */
    protected $servicesByPort = array();

    public function onServicePort(PortEvent $event)
    {
        $service = new Service($event->getPort());

        foreach ($event->getNode()->childNodes as $node) {
            if ($node->namespaceURI == self::SOAP_NS || $node->namespaceURI == self::SOAP12_NS) {
                $service->setAddress($node->getAttribute("location"));
            }
            if ($node->namespaceURI == self::SOAP12_NS) {
                $service->setVersion('1.2');
            }
        }

        $this->servicesByBinding [spl_object_hash($event->getPort()->getBinding())] = $service;
        $this->servicesByPort [spl_object_hash($event->getPort())] = $service;
    }

    public function onBinding(BindingEvent $event)
    {
        $service = $this->getServiceByBinding($event->getBinding());

        foreach ($event->getNode()->childNodes as $node) {
            if (($node->namespaceURI == self::SOAP12_NS || $node->namespaceURI == self::SOAP_NS) && $node->localName == 'binding') {
                $service->setTransport($node->getAttribute("transport"));
                if ($node->getAttribute("style")) {
                    $service->setStyle($node->getAttribute("style"));
                }
            }
        }
    }

    /**
     *
     * @param Binding $binging
     * @return Service
     */
    protected function getServiceByBinding(Binding $binging)
    {
        return $this->servicesByBinding [spl_object_hash($binging)];
    }

    /**
     *
     * @param Port $port
     * @return Service
     */
    public function getServiceByPort(Port $port)
    {
        return $this->servicesByPort [spl_object_hash($port)];
    }

    /**
     * @return Service[]
     */
    public function getServices()
    {
        return array_values($this->servicesByPort);
    }

    /**
     *
     * @var Operation[]
     */
    protected $operations = [];


    public function onBindingOperation(BindingOperationEvent $event)
    {
        $operation = new Operation($event->getOperation());

        if ($message = $event->getOperation()->getInput()) {
            $operation->setInput(new OperationMessage($message));
        }
        if ($message = $event->getOperation()->getOutput()) {
            $operation->setOutput(new OperationMessage($message));
        }
        $skip = true;
        foreach ($event->getNode()->childNodes as $node) {
            if (($node->namespaceURI == self::SOAP_NS || $node->namespaceURI == self::SOAP12_NS) && $node->localName == 'operation') {
                $skip = false;
                if ($node->getAttribute("soapAction")) {
                    $operation->setAction($node->getAttribute("soapAction"));
                }
                if ($node->namespaceURI == self::SOAP12_NS && $node->getAttribute("soapActionRequired")) {
                    $operation->setActionRequired($node->getAttribute("soapActionRequired") === 'true' || $node->getAttribute("soapActionRequired") === '1');
                }
                if ($node->getAttribute("style")) {
                    $operation->setStyle($node->getAttribute("style"));
                }

            }
        }

        $service = $this->getServiceByBinding($event->getOperation()
            ->getBinding());


        if ($service && !$skip) {
            $this->operations [spl_object_hash($event->getOperation())] = $operation;
            $service->addOperation($operation);
        }
    }

    public function onBindingOperationMessage(BindingOperationMessageEvent $event)
    {
        $operationMessage = $event->getOperationMessage();
        $operation = $operationMessage->getOperation();

        if (!isset($this->operations[spl_object_hash($operation)])) { // non soap 1.0 operation?
            return;
        }

        $soapOperation = $this->operations[spl_object_hash($operation)];

        $typeOperation = $operation->getBinding()
            ->getType()
            ->getOperation($operation->getName());

        $where = ucfirst($event->getType());

        $oMessage = new OperationMessage($operationMessage);


        $soapOperation->{"set" . $where}($oMessage);

        /**
         * @var $message \GoetasWebservices\XML\WSDLReader\Wsdl\Message
         */
        $message = $typeOperation->{"get" . $where}()->getMessage();

        if (!$message) {
            return;
        }

        foreach ($event->getNode()->childNodes as $node) {
            if ($node->namespaceURI !== self::SOAP_NS && ($node->namespaceURI !== self::SOAP12_NS)) {
                continue;
            }

            if ($node->localName == 'body') {
                $body = new Body();
                $this->fillBody($body, $message, $node);
                $oMessage->setBody($body);
            }
            if ($node->localName == 'header') {
                list ($name, $ns) = DefinitionsReader::splitParts($node, $node->getAttribute("message"));
                $hMessage = $event->getOperationMessage()->getDefinition()->findMessage($name, $ns);
                $header = new Header();
                $this->fillHeader($header, $hMessage, $node);
                $oMessage->addHeader($header);
            }
            /*
            if ($node->localName == 'headerfalt') {
                list ($name, $ns) = DefinitionsReader::splitParts($node, $node->getAttribute("message"));
                $hMessage = $event->getOperationMessage()->getDefinition()->findMessage($name, $ns);

                $header = new HeaderFault();
                $this->fillAbstractHeader($header, $message, $node);
                $oMessage->addHeaderFault($header);
            }
            */
        }
    }

    public function onBindingOperationFault(FaultEvent $event)
    {
        $operation = $event->getFault()->getOperation();
        $soapOperation = $this->operations [spl_object_hash($operation)];

        $typeOperation = $operation->getBinding()
            ->getType()
            ->getOperation($operation->getName());

        $message = $typeOperation->getFault($event->getFault()->getName())->getMessage();


        foreach ($event->getNode()->childNodes as $node) {
            if ($node->namespaceURI !== self::SOAP_NS && $node->namespaceURI !== self::SOAP12_NS) {
                continue;
            }
            if ($node->localName == 'fault') {
                $fault = new Fault($typeOperation->getFault($event->getFault()->getName()));
                $fault->setName($event->getNode()->getAttribute("name"));
                $this->fillBody($fault, $message, $node);

                $soapOperation->addFault($fault);
            }
        }
    }

    private function fillMessage(AbstractMessage $message, \DOMElement $node)
    {
        if ($node->getAttribute("namespace")) {
            $message->setNamespace($node->getAttribute("namespace"));
        }
        if ($node->getAttribute("use")) {
            $message->setUse($node->getAttribute("use"));
        }
        if ($node->getAttribute("encodingStyle")) {
            $message->setEncoding(explode(" ", $node->getAttribute("encodingStyle")));
        }
    }

    private function fillBody(Body $body, Message $message, \DOMElement $node)
    {
        if ($node->getAttribute("parts")) {
            $requiredParts = explode(" ", $node->getAttribute("parts"));
            $body->setParts(array_filter($message->getParts(), function (Part $part) use ($requiredParts) {
                return in_array($part->getName(), $requiredParts);
            }));
        } else {
            $body->setParts($message->getParts());
        }

        $this->fillMessage($body, $node);
    }

    private function fillAbstractHeader(AbstractHeader $header, Message $message, \DOMElement $node)
    {
        $header->setPart($message->getPart($node->getAttribute("part")));
        $this->fillMessage($header, $node);
    }

    private function fillHeader(Header $header, Message $message, \DOMElement $node)
    {
        $this->fillAbstractHeader($header, $message, $node);
        foreach ($node->childNodes as $childNode) {
            if (($childNode->namespaceURI == self::SOAP_NS || $node->namespaceURI == self::SOAP12_NS) && $childNode->localName == 'headerfault') {

                list ($name, $ns) = DefinitionsReader::splitParts($node, $node->getAttribute("message"));
                $hMessage = $message->getDefinition()->findMessage($name, $ns);

                $headerFault = new HeaderFault();
                $this->fillAbstractHeader($headerFault, $hMessage, $childNode);
                $header->addFault($headerFault);
            }
        }
    }
}
