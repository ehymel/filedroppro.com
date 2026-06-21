<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Form to capture administrative invitations.
 */
class InvitationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Recipient Email Address',
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
