<?php

namespace App\Controller\Security;

use App\Entity\User;
use App\Form\User\UserEditForm;
use App\Form\User\UserPasswordResetForm;
use App\Form\User\UserPasswordResetRequestForm;
use App\Form\User\UserPasswordSetForm;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('user', name: 'user_')]
class UserController extends AbstractController
{
    public function __construct(private readonly string $kernelSecret) {}

    #[Route(path: '/edit', name: 'edit')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function edit(Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $form = $this->createForm(UserEditForm::class, $user);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $user */
            $user = $form->getData();

            if ($user->plainPassword) {
                $user->password = $passwordHasher->hashPassword($user, $user->plainPassword);
            }

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'User profile updated.');

            return $this->redirectToRoute('user_edit');
        }

        return $this->render('user/edit.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/activate/{id}/{hash}', name: 'activate')]
    public function activate(User $user, Request $request, EntityManagerInterface $em): Response
    {
        $submittedHash = $request->attributes->get('hash');
        $savedHash = $user->confirmationHash;

        if ($submittedHash === $savedHash) {
            // Display "is activated" in admin user edit page
            // has no effect on login!!
            $user->isActivated = true;

            // create new confirmation hash to pass to set_password script (for security)
            $hash = md5(time().$this->kernelSecret);
            $user->confirmationHash = $hash;

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'Your account has been activated.');

            return $this->redirectToRoute('user_set_password', [
                'id' => $user->id,
                'hash' => $hash,
            ]);
        }

        return $this->render('user/activation_failed.html.twig');
    }

    #[Route(path: '/password_set/{id}/{hash}', name: 'set_password')]
    public function setPassword(User $user, Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $em): ?Response
    {
        $submittedHash = $request->attributes->get('hash');
        $savedHash = $user->confirmationHash;

        if ($submittedHash !== $savedHash) {
            return $this->render('user/password_reset_failed.html.twig');
        }

        $form = $this->createForm(UserPasswordSetForm::class, $user);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $user */
            $user = $form->getData();

            $user->password = $passwordHasher->hashPassword($user, $user->plainPassword);
            $user->confirmationHash = null;

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'Welcome '.$user->name);

            return $this->redirectToRoute('security_login');
        }

        return $this->render('user/password_set.html.twig', [
            'form' => $form,
            'email' => $user->getUserIdentifier(),
        ]);
    }

    #[Route(path: '/password_reset/{id}/{hash}', name: 'reset_password')]
    public function resetPassword(User $user, Request $request, UserPasswordHasherInterface $passwordHasher,
                                  TokenStorageInterface $tokenStorage, EntityManagerInterface $em): Response
    {
        // force logout of previous user
        $tokenStorage->setToken(null);

        $submittedHash = $request->attributes->get('hash');
        $savedHash = $user->confirmationHash;

        if ($submittedHash !== $savedHash) {
            return $this->render('user/password_reset_failed.html.twig');
        }

        $form = $this->createForm(UserPasswordResetForm::class, $user);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $user */
            $user = $form->getData();

            $user->password = $passwordHasher->hashPassword($user, $user->plainPassword);
            $user->confirmationHash = null;

            $em->persist($user);
            $em->flush();

            $this->addFlash(
                'success',
                'Successfully changed password for '.$user->name
            );

            return $this->redirectToRoute('login_redirect');
        }

        return $this->render('user/password_reset.html.twig', [
            'form' => $form,
            'email' => $user->getUserIdentifier(),
        ]);
    }

    #[Route(path: '/password_reset_request', name: 'request_reset_pw')]
    public function requestResetPwLink(Request $request, MailerInterface $mailer, UserRepository $userRepository, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(UserPasswordResetRequestForm::class);

        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            $data = $form->getData();

            $filters = $userRepository->getEntityManager()->getFilters();
            $tenantFilterEnabled = $filters->isEnabled('tenant_filter');
            if ($tenantFilterEnabled) {
                $filters->disable('tenant_filter');
            }

            $user = $userRepository->findOneByEmail($data['_email']);

            if ($tenantFilterEnabled) {
                $filters->enable('tenant_filter');
            }

            if (!$user instanceof User) {
                $this->addFlash(
                    'danger',
                    'No user found with that email address. Try again?'
                );

                return $this->redirectToRoute('user_request_reset_pw');
            }

            // generate random hash and persist to db for later comparison from link in reset password email
            $hash = md5(time().$this->kernelSecret);
            $user->confirmationHash = $hash;

            $em->persist($user);
            $em->flush();

            // now send link to password reset page email with above hash
            $email = new TemplatedEmail()
                ->to($user->email)
                ->subject('FileDrop Pro Password Reset')
                ->htmlTemplate('emails/user_reset_password.html.twig')
                ->context([
                    'user' => $user,
                    'hash' => $hash,
                ])
            ;

            $mailer->send($email);

            $this->addFlash(
                'success',
                'If an account matching your email exists, then an email was just sent that contains a link that you can use to reset your password.'
            );

            return $this->redirect('/login');
        }

        return $this->render('user/password_reset_request.html.twig', [
            'form' => $form,
        ]);
    }
}
