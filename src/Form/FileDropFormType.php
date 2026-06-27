<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

class FileDropFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('senderName', TextType::class, [
                'label' => 'Your name',
                'required' => true,
                'mapped' => false,
                'constraints' => [
                    new NotBlank(message: 'Please provide your name.'),
                ],
                'row_attr' => ['class' => 'form-floating mb-2'],
            ])
            ->add('senderEmail', EmailType::class, [
                'label' => 'Your Email Address',
                'required' => true,
                'attr' => [
                    'placeholder' => 'colleague@firm.com',
                ],
                'constraints' => [
                    new NotBlank(message:  'Please provide an email address for the invitee.'),
                    new Email(message:  'Please enter a valid business email address.'),
                ],
                'row_attr' => ['class' => 'form-floating mb-2'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // Unmapped form so we can manually handle generation logic in the controller
        ]);
    }
}
