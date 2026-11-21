from __future__ import annotations
from queue import Empty ,Queue
from typing import Iterable
from .models import GuiEvent
class GuiEventBus :
    def __init__ (self )->None :
        self ._queue :Queue [GuiEvent ]=Queue ()
    def post (self ,name :str ,payload :object =None ,level :str ="info",message :str ="")->None :
        self ._queue .put (GuiEvent (name =name ,payload =payload ,level =level ,message =message ))
    def post_error (self ,message :str ,payload :object =None )->None :
        self .post ("error",payload =payload ,level ="error",message =message )
    def drain (self ,limit :int =100 )->Iterable [GuiEvent ]:
        for _ in range (limit ):
            try :
                yield self ._queue .get_nowait ()
            except Empty :
                break