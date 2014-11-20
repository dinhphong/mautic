<?php
/**
 * @package     Mautic
 * @copyright   2014 Mautic, NP. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.com
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
//@todo - add support to editing more than one form at a time (i.e. opened in different tabs)

namespace Mautic\CampaignBundle\Controller;

use Mautic\CoreBundle\Controller\FormController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CampaignController extends FormController
{
    /**
     * @param int    $page
     * @param string $view
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function indexAction ($page = 1)
    {
        //set some permissions
        $permissions = $this->factory->getSecurity()->isGranted(array(
            'campaign:campaigns:view',
            'campaign:campaigns:create',
            'campaign:campaigns:edit',
            'campaign:campaigns:delete',
            'campaign:campaigns:publish'

        ), "RETURN_ARRAY");

        if (!$permissions['campaign:campaigns:view']) {
            return $this->accessDenied();
        }

        if ($this->request->getMethod() == 'POST') {
            $this->setTableOrder();
        }

        //set limits
        $limit = $this->factory->getSession()->get('mautic.campaign.limit', $this->factory->getParameter('default_pagelimit'));
        $start = ($page === 1) ? 0 : (($page - 1) * $limit);
        if ($start < 0) {
            $start = 0;
        }

        $search = $this->request->get('search', $this->factory->getSession()->get('mautic.campaign.filter', ''));
        $this->factory->getSession()->set('mautic.campaign.filter', $search);

        $filter     = array('string' => $search, 'force' => array());
        $orderBy    = $this->factory->getSession()->get('mautic.campaign.orderby', 'c.name');
        $orderByDir = $this->factory->getSession()->get('mautic.campaign.orderbydir', 'ASC');

        $campaigns = $this->factory->getModel('campaign')->getEntities(
            array(
                'start'      => $start,
                'limit'      => $limit,
                'filter'     => $filter,
                'orderBy'    => $orderBy,
                'orderByDir' => $orderByDir
            )
        );

        $count = count($campaigns);
        if ($count && $count < ($start + 1)) {
            //the number of entities are now less then the current page so redirect to the last page
            if ($count === 1) {
                $lastPage = 1;
            } else {
                $lastPage = (floor($limit / $count)) ?: 1;
            }
            $this->factory->getSession()->set('mautic.campaign.page', $lastPage);
            $returnUrl = $this->generateUrl('mautic_campaign_index', array('page' => $lastPage));

            return $this->postActionRedirect(array(
                'returnUrl'       => $returnUrl,
                'viewParameters'  => array('page' => $lastPage),
                'contentTemplate' => 'MauticCampaignBundle:Campaign:index',
                'passthroughVars' => array(
                    'activeLink'    => '#mautic_campaign_index',
                    'mauticContent' => 'campaign'
                )
            ));
        }

        //set what page currently on so that we can return here after form submission/cancellation
        $this->factory->getSession()->set('mautic.campaign.page', $page);

        $tmpl = $this->request->isXmlHttpRequest() ? $this->request->get('tmpl', 'index') : 'index';

        return $this->delegateView(array(
            'viewParameters'  => array(
                'searchValue' => $search,
                'items'       => $campaigns,
                'page'        => $page,
                'limit'       => $limit,
                'permissions' => $permissions,
                'tmpl'        => $tmpl
            ),
            'contentTemplate' => 'MauticCampaignBundle:Campaign:list.html.php',
            'passthroughVars' => array(
                'activeLink'     => '#mautic_campaign_index',
                'mauticContent'  => 'campaign',
                'route'          => $this->generateUrl('mautic_campaign_index', array('page' => $page))
            )
        ));
    }

    /**
     * View a specific campaign
     *
     * @param $objectId
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function viewAction ($objectId)
    {
        $entity      = $this->factory->getModel('campaign')->getEntity($objectId);
        //set the page we came from
        $page        = $this->factory->getSession()->get('mautic.campaign.page', 1);
        $model       = $this->factory->getModel('campaign.campaign');
        $security    = $this->factory->getSecurity();
        $activePage  = $model->getEntity($objectId);

        $permissions = $security->isGranted(array(
            'campaign:campaigns:view',
            'campaign:campaigns:create',
            'campaign:campaigns:edit',
            'campaign:campaigns:delete',
            'campaign:campaigns:publish'
        ), "RETURN_ARRAY");


        if ($entity === null) {
            //set the return URL
            $returnUrl = $this->generateUrl('mautic_campaign_index', array('page' => $page));

            return $this->postActionRedirect(array(
                'returnUrl'       => $returnUrl,
                'viewParameters'  => array('page' => $page),
                'contentTemplate' => 'MauticCampaignBundle:Campaign:index',
                'passthroughVars' => array(
                    'activeLink'    => '#mautic_campaign_index',
                    'mauticContent' => 'campaign'
                ),
                'flashes'         => array(
                    array(
                        'type'    => 'error',
                        'msg'     => 'mautic.campaign.error.notfound',
                        'msgVars' => array('%id%' => $objectId)
                    )
                )
            ));
        } elseif (!$permissions['campaign:campaigns:view']) {
            return $this->accessDenied();
        }

        $eventLogRepo       = $this->factory->getEntityManager()->getRepository('MauticCampaignBundle:LeadEventLog');
        $campaignLeadRepo   = $this->factory->getEntityManager()->getRepository('MauticCampaignBundle:Lead');
        $events             = $this->factory->getEntityManager()->getRepository('MauticCampaignBundle:Event')->getEvents(array('campaigns' => array($entity->getId())));
        $leadCount          = $campaignLeadRepo->countLeads($entity->getId());
        $campaignLogs       = $eventLogRepo->getCampaignLog($entity->getId());
        $leads              = $campaignLeadRepo->getLeadsWithFields(array('campaign_id' => $entity->getId(), 'withTotalCount' => true));

        foreach ($events  as &$event) {
            $event['logCount'] = 0;
            $event['percent'] = 0;
            if (isset($campaignLogs[$event['id']])) {
                $event['logCount'] = count($campaignLogs[$event['id']]);
            }
            if ($leadCount) {
                $event['percent'] = round($event['logCount'] / $leadCount * 100);
            }
        }

        // Audit Log
        $logs = $this->factory->getModel('core.auditLog')->getLogForObject('campaign', $objectId);

        // Hit count per day for last 30 days
        $hits = $this->factory->getEntityManager()->getRepository('MauticPageBundle:Hit')->getHits(30, 'D', array('source_id' => $entity->getId(), 'source' => 'campaign'));

        // Sent emails stats
        $emailsSent = $this->factory->getEntityManager()->getRepository('MauticEmailBundle:Stat')->getIgnoredReadFailed(null, array('source_id' => $entity->getId(), 'source' => 'campaign'));

        // Lead count stats
        $leadStats = $campaignLeadRepo->getLeadStats(30, 'D');

        // We need the EmailRepository to check if a lead is flagged as do not contact
        /** @var \Mautic\EmailBundle\Entity\EmailRepository $emailRepo */
        $emailRepo = $this->factory->getModel('email')->getRepository();

        return $this->delegateView(array(
            'viewParameters'  => array(
                'campaign'    => $entity,
                'page'        => $page,
                'permissions' => $permissions,
                'activePage'  => $activePage,
                'security'    => $security,
                'logs'        => $logs,
                'hits'        => $hits,
                'emailsSent'  => $emailsSent,
                'leadStats'   => $leadStats,
                'events'      => $events,
                'leads'       => $leads,
                'noContactList' => $emailRepo->getDoNotEmailList()
            ),
            'contentTemplate' => 'MauticCampaignBundle:Campaign:details.html.php',
            'passthroughVars' => array(
                'activeLink'    => '#mautic_campaign_index',
                'mauticContent' => 'campaign',
                'route'         => $this->generateUrl('mautic_campaign_action', array(
                        'objectAction' => 'view',
                        'objectId'     => $entity->getId())
                )
            )
        ));

        return $this->indexAction($page, 'view', $entity);
    }

    /**
     * Generates new form and processes post data
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function newAction ()
    {
        /** @var \Mautic\CampaignBundle\Model\CampaignModel $model */
        $model   = $this->factory->getModel('campaign');

        /** @var \Mautic\CampaignBundle\Model\EventModel $model */
        $eventModel = $this->factory->getModel('campaign.event');

        $entity  = $model->getEntity();
        $session = $this->factory->getSession();

        if (!$this->factory->getSecurity()->isGranted('campaign:campaigns:create')) {
            return $this->accessDenied();
        }

        //set the page we came from
        $page = $this->factory->getSession()->get('mautic.campaign.page', 1);

        //set added/updated events
        $addEvents     = $session->get('mautic.campaigns.add', array());
        $deletedEvents = $session->get('mautic.campaigns.remove', array());

        $action        = $this->generateUrl('mautic_campaign_action', array('objectAction' => 'new'));
        $form          = $model->createForm($entity, $this->get('form.factory'), $action);
        $eventSettings = $model->getEvents();

        ///Check for a submitted form and process it
        if ($this->request->getMethod() == 'POST') {
            $valid = false;
            if (!$cancelled = $this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {
                    //only save events that are not to be deleted
                    $events = array_diff_key($addEvents, array_flip($deletedEvents));

                    //make sure that at least one action is selected
                    if (empty($events)) {
                        //set the error
                        $form->addError(new FormError(
                            $this->get('translator')->trans('mautic.campaign.form.events.notempty', array(), 'validators')
                        ));
                        $valid = false;
                    } else {
                        $connections = $session->get('mautic.campaigns.connections');
                        $processedEvents = $model->setEvents($entity, $events, $connections, $deletedEvents);

                        //form is valid so process the data
                        $model->saveEntity($entity);

                        //trigger the first action in dripflow if published and first event is an action
                        if ($entity->isPublished()) {
                            //check for top level action events
                            foreach ($processedEvents as $id => $e) {
                                $parent = $e->getParent();
                                if ($e->getEventType() == 'action' && $parent === null) {
                                    //check the callback function for the event to make sure it even applies based on its settings
                                    $eventModel->triggerCampaignStartingAction($entity, $e, $eventSettings['action'][$e->getType()]);
                                }
                            }
                        }

                        $this->request->getSession()->getFlashBag()->add(
                            'notice',
                            $this->get('translator')->trans('mautic.core.notice.created', array(
                                '%name%'      => $entity->getName(),
                                '%menu_link%' => 'mautic_campaign_index',
                                '%url%'       => $this->generateUrl('mautic_campaign_action', array(
                                    'objectAction' => 'edit',
                                    'objectId'     => $entity->getId()
                                ))
                            ), 'flashes')
                        );

                        if ($form->get('buttons')->get('save')->isClicked()) {
                            $viewParameters = array(
                                'objectAction' => 'view',
                                'objectId'     => $entity->getId()
                            );
                            $returnUrl      = $this->generateUrl('mautic_campaign_action', $viewParameters);
                            $template       = 'MauticCampaignBundle:Campaign:view';
                        } else {
                            //return edit view so that all the session stuff is loaded
                            return $this->editAction($entity->getId(), true);
                        }
                    }
                }
            } else {
                $viewParameters = array('page' => $page);
                $returnUrl      = $this->generateUrl('mautic_campaign_index', $viewParameters);
                $template       = 'MauticCampaignBundle:Campaign:index';
            }

            if ($cancelled || ($valid && $form->get('buttons')->get('save')->isClicked())) {
                //clear temporary fields
                $this->clearSessionComponents();

                return $this->postActionRedirect(array(
                    'returnUrl'       => $returnUrl,
                    'viewParameters'  => $viewParameters,
                    'contentTemplate' => $template,
                    'passthroughVars' => array(
                        'activeLink'    => '#mautic_campaign_index',
                        'mauticContent' => 'campaign'
                    )
                ));
            }
        } else {
            //clear out existing fields in case the form was refreshed, browser closed, etc
            $this->clearSessionComponents();
            $addEvents = $deletedEvents = array();
        }

        return $this->delegateView(array(
            'viewParameters'  => array(
                'eventSettings'  => $eventSettings,
                'campaignEvents' => $addEvents,
                'tempEventIds'   => $this->associateIdWithTempIds($addEvents),
                'deletedEvents'  => $deletedEvents,
                'tmpl'           => $this->request->isXmlHttpRequest() ? $this->request->get('tmpl', 'index') : 'index',
                'entity'         => $entity,
                'form'           => $form->createView()
            ),
            'contentTemplate' => 'MauticCampaignBundle:Campaign:form.html.php',
            'passthroughVars' => array(
                'activeLink'    => '#mautic_campaign_index',
                'mauticContent' => 'campaign',
                'route'         => $this->generateUrl('mautic_campaign_action', array(
                    'objectAction' => (!empty($valid) ? 'edit' : 'new'), //valid means a new form was applied
                    'objectId'     => $entity->getId())
                )
            )
        ));
    }

    /**
     * Generates edit form and processes post data
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function editAction ($objectId, $ignorePost = false)
    {
        /** @var \Mautic\CampaignBundle\Model\CampaignModel $model */
        $model      = $this->factory->getModel('campaign');

        /** @var \Mautic\CampaignBundle\Model\EventModel $model */
        $eventModel = $this->factory->getModel('campaign.event');

        $entity     = $model->getEntity($objectId);
        $session    = $this->factory->getSession();
        $cleanSlate = true;
        //set the page we came from
        $page = $this->factory->getSession()->get('mautic.campaign.page', 1);

        //set the return URL
        $returnUrl = $this->generateUrl('mautic_campaign_index', array('page' => $page));

        $postActionVars = array(
            'returnUrl'       => $returnUrl,
            'viewParameters'  => array('page' => $page),
            'contentTemplate' => 'MauticCampaignBundle:Campaign:index',
            'passthroughVars' => array(
                'activeLink'    => '#mautic_campaign_index',
                'mauticContent' => 'campaign'
            )
        );
        //form not found
        if ($entity === null) {
            return $this->postActionRedirect(
                array_merge($postActionVars, array(
                    'flashes' => array(
                        array(
                            'type'    => 'error',
                            'msg'     => 'mautic.campaign.error.notfound',
                            'msgVars' => array('%id%' => $objectId)
                        )
                    )
                ))
            );
        } elseif (!$this->factory->getSecurity()->isGranted('campaign:campaigns:edit')) {
            return $this->accessDenied();
        } elseif ($model->isLocked($entity)) {
            //deny access if the entity is locked
            return $this->isLocked($postActionVars, $entity, 'campaign');
        }

        $action = $this->generateUrl('mautic_campaign_action', array('objectAction' => 'edit', 'objectId' => $objectId));
        $form   = $model->createForm($entity, $this->get('form.factory'), $action);

        $eventSettings = $model->getEvents();

        ///Check for a submitted form and process it
        if (!$ignorePost && $this->request->getMethod() == 'POST') {
            $valid = false;
            if (!$cancelled = $this->isFormCancelled($form)) {
                //set added/updated events
                $addEvents     = $session->get('mautic.campaigns.add', array());
                $deletedEvents = $session->get('mautic.campaigns.remove', array());
                $events        = array_diff_key($addEvents, array_flip($deletedEvents));

                if ($valid = $this->isFormValid($form)) {
                    //make sure that at least one field is selected
                    if (empty($addEvents)) {
                        //set the error
                        $form->addError(new FormError(
                            $this->get('translator')->trans('mautic.campaign.form.events.notempty', array(), 'validators')
                        ));
                        $valid = false;
                    } else {
                        $connections = $session->get('mautic.campaigns.connections');
                        $processedEvents = $model->setEvents($entity, $events, $connections, $deletedEvents);

                        //form is valid so process the data
                        $model->saveEntity($entity, $form->get('buttons')->get('save')->isClicked());

                        //trigger the first action in dripflow if published and first event is an action
                        if ($entity->isPublished()) {
                            //check for top level action events
                            foreach ($processedEvents as $id => $e) {
                                $parent = $e->getParent();
                                if ($e->getEventType() == 'action' && $parent === null) {
                                    //check the callback function for the event to make sure it even applies based on its settings
                                    $eventModel->triggerCampaignStartingAction($entity, $e, $eventSettings['action'][$e->getType()]);
                                }
                            }
                        }

                        if (!empty($deletedEvents)) {
                            $this->factory->getModel('campaign.event')->deleteEvents($entity->getEvents(), $addEvents, $deletedEvents);
                        }

                        $this->request->getSession()->getFlashBag()->add(
                            'notice',
                            $this->get('translator')->trans('mautic.core.notice.updated', array(
                                '%name%'      => $entity->getName(),
                                '%menu_link%' => 'mautic_campaign_index',
                                '%url%'       => $this->generateUrl('mautic_campaign_action', array(
                                    'objectAction' => 'edit',
                                    'objectId'     => $entity->getId()
                                ))
                            ), 'flashes')
                        );

                        if ($form->get('buttons')->get('save')->isClicked()) {
                            $viewParameters = array(
                                'objectAction' => 'view',
                                'objectId'     => $entity->getId()
                            );
                            $returnUrl      = $this->generateUrl('mautic_campaign_action', $viewParameters);
                            $template       = 'MauticCampaignBundle:Campaign:view';
                        }
                    }
                }
            } else {
                //unlock the entity
                $model->unlockEntity($entity);

                $viewParameters = array('page' => $page);
                $returnUrl      = $this->generateUrl('mautic_campaign_index', $viewParameters);
                $template       = 'MauticCampaignBundle:Campaign:index';
            }

            if ($cancelled || ($valid && $form->get('buttons')->get('save')->isClicked())) {
                //remove fields from session
                $this->clearSessionComponents();

                return $this->postActionRedirect(
                    array_merge($postActionVars, array(
                        'returnUrl'       => $returnUrl,
                        'viewParameters'  => $viewParameters,
                        'contentTemplate' => $template
                    ))
                );
            } elseif ($form->get('buttons')->get('apply')->isClicked()) {
                //rebuild everything to include new ids
                $cleanSlate = true;
            }
        } else {
            $cleanSlate = true;

            //lock the entity
            $model->lockEntity($entity);
        }

        if ($cleanSlate) {
            //clean slate
            $this->clearSessionComponents();

            //load existing events into session
            $campaignEvents  = array();
            $existingActions = $entity->getEvents()->toArray();
            foreach ($existingActions as $a) {
                $id     = $a->getId();
                $action = $a->convertToArray();
                unset($action['form']);
                $campaignEvents[$id] = $action;
            }
            $session->set('mautic.campaigns.add', $campaignEvents);
            $deletedEvents = array();
        }

        return $this->delegateView(array(
            'viewParameters'  => array(
                'eventSettings'  => $eventSettings,
                'campaignEvents' => $campaignEvents,
                'tempEventIds'   => $this->associateIdWithTempIds($campaignEvents),
                'deletedEvents'  => $deletedEvents,
                'tmpl'           => $this->request->isXmlHttpRequest() ? $this->request->get('tmpl', 'index') : 'index',
                'entity'         => $entity,
                'form'           => $form->createView()
            ),
            'contentTemplate' => 'MauticCampaignBundle:Campaign:form.html.php',
            'passthroughVars' => array(
                'activeLink'    => '#mautic_campaign_index',
                'mauticContent' => 'campaign',
                'route'         => $this->generateUrl('mautic_campaign_action', array(
                        'objectAction' => 'edit',
                        'objectId'     => $entity->getId())
                )
            )
        ));
    }

    /**
     * Clone an entity
     *
     * @param $objectId
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function cloneAction ($objectId)
    {
        $model  = $this->factory->getModel('campaign');
        $entity = $model->getEntity($objectId);

        if ($entity != null) {
            if (!$this->factory->getSecurity()->isGranted('campaign:campaigns:create')) {
                return $this->accessDenied();
            }

            $clone = clone $entity;
            $clone->setIsPublished(false);
            $model->saveEntity($clone);
            $objectId = $clone->getId();
        }

        return $this->editAction($objectId);
    }

    /**
     * Deletes the entity
     *
     * @param         $objectId
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function deleteAction ($objectId)
    {
        $page      = $this->factory->getSession()->get('mautic.campaign.page', 1);
        $returnUrl = $this->generateUrl('mautic_campaign_index', array('page' => $page));
        $flashes   = array();

        $postActionVars = array(
            'returnUrl'       => $returnUrl,
            'viewParameters'  => array('page' => $page),
            'contentTemplate' => 'MauticCampaignBundle:Campaign:index',
            'passthroughVars' => array(
                'activeLink'    => '#mautic_campaign_index',
                'mauticContent' => 'campaign'
            )
        );

        if ($this->request->getMethod() == 'POST') {
            $model  = $this->factory->getModel('campaign');
            $entity = $model->getEntity($objectId);

            if ($entity === null) {
                $flashes[] = array(
                    'type'    => 'error',
                    'msg'     => 'mautic.campaign.error.notfound',
                    'msgVars' => array('%id%' => $objectId)
                );
            } elseif (!$this->factory->getSecurity()->isGranted('campaign:campaigns:delete')) {
                return $this->accessDenied();
            } elseif ($model->isLocked($entity)) {
                return $this->isLocked($postActionVars, $entity, 'campaign');
            }

            $model->deleteEntity($entity);

            $identifier = $this->get('translator')->trans($entity->getName());
            $flashes[]  = array(
                'type'    => 'notice',
                'msg'     => 'mautic.core.notice.deleted',
                'msgVars' => array(
                    '%name%' => $identifier,
                    '%id%'   => $objectId
                )
            );
        } //else don't do anything

        return $this->postActionRedirect(
            array_merge($postActionVars, array(
                'flashes' => $flashes
            ))
        );
    }

    /**
     * Clear field and events from the session
     */
    private function clearSessionComponents ()
    {
        $session = $this->factory->getSession();
        $session->remove('mautic.campaigns.add');
        $session->remove('mautic.campaigns.remove');
        $session->remove('mautic.campaigns.connections');
    }

    /**
     * @param $events
     *
     * @return array
     */
    private function associateIdWithTempIds($events)
    {
        $tempIds = array();
        foreach ($events as $e) {
            $tempIds[$e['tempId']] = $e['id'];
        }
        return $tempIds;
    }
}
