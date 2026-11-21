import unittest, threading, logging, time
from types import SimpleNamespace
from fitodomik_app.core.task_manager import TaskManager
from fitodomik_app.ui.app import FitoDomikApplication
from fitodomik_app.ui.widgets import ScrollableFrame
class LifecycleTests(unittest.TestCase):
 def test_shutdown_waits_for_once_job_and_rejects_new_work(self):
  bus=SimpleNamespace(post_error=lambda *args:None); manager=TaskManager(bus,logging.getLogger('test'))
  started=threading.Event(); release=threading.Event(); done=threading.Event()
  manager.run_once('network',lambda:(started.set(),release.wait(),done.set())); self.assertTrue(started.wait(1))
  manager.request_stop_all(); manager.run_once('late',lambda:self.fail('late job started'))
  stopper=threading.Thread(target=manager.stop_all); stopper.start(); time.sleep(.03); self.assertTrue(stopper.is_alive());release.set();stopper.join(2);self.assertFalse(stopper.is_alive());self.assertTrue(done.is_set())
 def test_camera_and_settings_finish_after_workers(self):
  app=FitoDomikApplication.__new__(FitoDomikApplication); order=[]
  app.task_manager=SimpleNamespace(stop_all=lambda:order.append('workers'));app.arduino=SimpleNamespace(stop=lambda:order.append('serial'));app.plant_analyzer=SimpleNamespace(release_camera=lambda:order.append('camera'));app.settings=SimpleNamespace(save=lambda:order.append('fsync'));app._shutdown_done=threading.Event();app.logger=logging.getLogger('test');app._shutdown_services();self.assertEqual(order,['workers','serial','camera','fsync']);self.assertTrue(app._shutdown_done.is_set())
 def test_destroyed_child_can_be_rebound_without_retaining_name(self):
  frame=SimpleNamespace(_bound_widgets=set(),_on_touch_start=lambda e:None,_on_touch_move=lambda e:None,_on_mousewheel=lambda e:None)
  callbacks={}
  class Child:
   def __str__(self):return '.frame.child'
   def bind(self,event,callback,**kwargs):callbacks[event]=callback
   def winfo_children(self):return []
  child=Child();ScrollableFrame._bind_scroll_children(frame,child);self.assertIn(str(child),frame._bound_widgets);callbacks['<Destroy>'](None);self.assertFalse(frame._bound_widgets);ScrollableFrame._bind_scroll_children(frame,child);self.assertIn(str(child),frame._bound_widgets)
if __name__=='__main__':unittest.main()
