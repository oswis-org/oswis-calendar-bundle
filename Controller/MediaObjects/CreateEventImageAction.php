<?php

namespace Zakjakub\OswisCalendarBundle\Controller\MediaObjects;

use ApiPlatform\Core\Bridge\Symfony\Validator\Exception\ValidationException;
use Psr\Log\LoggerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Zakjakub\OswisCalendarBundle\Entity\MediaObjects\EventImage;
use Zakjakub\OswisCalendarBundle\Form\MediaObjects\EventImageType;

final class CreateEventImageAction
{
    /**
     * @var ValidatorInterface
     */
    private $validator;

    /**
     * @var RegistryInterface
     */
    private $doctrine;

    /**
     * @var FormFactoryInterface
     */
    private $factory;

    private $logger;

    /**
     * @param RegistryInterface    $doctrine
     * @param FormFactoryInterface $factory
     * @param ValidatorInterface   $validator
     */
    public function __construct(
        RegistryInterface $doctrine,
        FormFactoryInterface $factory,
        ValidatorInterface $validator,
        LoggerInterface $logger
    ) {
        $this->validator = $validator;
        $this->doctrine = $doctrine;
        $this->factory = $factory;
        $this->logger = $logger;
    }

    /**
     * @param Request $request
     *
     * @return EventImage
     * @throws ValidationException
     * @throws \Symfony\Component\Form\Exception\LogicException
     * @throws \Symfony\Component\OptionsResolver\Exception\InvalidOptionsException
     * @IsGranted("ROLE_MANAGER")
     */
    public function __invoke(Request $request): EventImage
    {
        $mediaObject = new EventImage();

        $form = $this->factory->create(EventImageType::class, $mediaObject);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->doctrine->getManager();
            $em->persist($mediaObject);
            try {
                $em->flush();
            } catch (\Exception $exception) {
                $this->logger->error($exception->getMessage());
                $this->logger->error($exception->getTraceAsString());
            }
            // Prevent the serialization of the file property
            $mediaObject->file = null;

            return $mediaObject;
        }

        // This will be handled by API Platform and returns a validation error.
        throw new ValidationException($this->validator->validate($mediaObject));
    }
}
