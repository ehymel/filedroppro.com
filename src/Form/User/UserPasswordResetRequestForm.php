<?php

namespace App\Form\User;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserPasswordResetRequestForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('_username', null, [
                'row_attr' => ['class' => 'form-floating'],
            ])
            ->add('_email', EmailType::class, [
                'row_attr' => ['class' => 'form-floating'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
    }
}
